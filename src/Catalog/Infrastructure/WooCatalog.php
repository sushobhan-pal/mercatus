<?php
/**
 * The WooCommerce product adapter (ARCH-12, G-DB-4, CTL-1, CTL-2, CTL-21).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Catalog\Infrastructure;

use Mercatus\Core\Catalog\Domain\Balance;
use Mercatus\Core\Catalog\Domain\EffectivePriceResolver;
use Mercatus\Core\Catalog\Domain\Prices;
use Mercatus\Core\Catalog\Domain\Product;
use Mercatus\Core\Shared\Clock\Clock;
use Mercatus\Core\Shared\Errors\DomainError;
use Throwable;
use WC_Data_Exception;
use WC_DateTime;
use WC_Product;
use WC_Product_Attribute;
use WC_Product_Simple;
use WC_Product_Variable;
use WC_Product_Variation;
use stdClass;
use WP_Error;
use WP_Term;

/**
 * Content lives in the WooCommerce product and is written only through WooCommerce CRUD; prices and stock are
 * copies of Mercatus's offers and balances (CTL-2). While Mercatus writes, `writing()` is true, so its own
 * saves aren't adopted back as outside edits (CTL-11), and `seller()` names whose SKUs the save is checked
 * against (CTL-26). Tags and attribute values a vendor names are found ignoring case, or created (CTL-21).
 */
final class WooCatalog {

	public const SELLER_META = '_mercatus_seller';

	private const TAGS = 'product_tag';

	private const CATEGORIES = 'product_cat';

	private int $writing = 0;

	private ?int $seller = null;

	/**
	 * Terms created during the current write, for the audit log.
	 *
	 * @var list<array{taxonomy: string, term_id: int}>
	 */
	private array $created = array();

	/**
	 * Creates the adapter.
	 *
	 * @param Clock                  $clock    Clock, for the active price.
	 * @param EffectivePriceResolver $resolver Effective prices.
	 */
	public function __construct( private Clock $clock, private EffectivePriceResolver $resolver ) {}

	/**
	 * Whether Mercatus itself is writing to WooCommerce now.
	 */
	public function writing(): bool {
		return $this->writing > 0;
	}

	/**
	 * The seller whose SKUs the current Mercatus write is checked against, if any.
	 */
	public function seller(): ?int {
		return $this->seller;
	}

	/**
	 * Creates a product with its content, copies and variations.
	 *
	 * @param string                                                                     $type       simple or variable.
	 * @param array<string, mixed>                                                       $content    Valid content (ProductInput).
	 * @param int                                                                        $seller     Seller, for the SKU rule and the marker.
	 * @param string                                                                     $status     WooCommerce status.
	 * @param Prices                                                                     $prices     A simple product's prices.
	 * @param Balance|null                                                               $balance    A simple product's stock.
	 * @param list<array{content: array<string, mixed>, prices: Prices, stock: Balance}> $variations New variations, in order.
	 * @return array{id: int, variations: list<int>, terms: list<array{taxonomy: string, term_id: int}>}
	 * @throws DomainError When WooCommerce refuses a field, such as a taken SKU.
	 */
	public function create( string $type, array $content, int $seller, string $status, Prices $prices, ?Balance $balance, array $variations ): array {
		$result = $this->write(
			$seller,
			function () use ( $type, $content, $seller, $status, $prices, $balance, $variations ): array {
				$product = Product::VARIABLE === $type ? new WC_Product_Variable() : new WC_Product_Simple();
				$product->update_meta_data( self::SELLER_META, (string) $seller );
				$this->apply( $product, $content );
				$product->set_status( $status );
				if ( Product::SIMPLE === $type ) {
					$this->copyPrices( $product, $prices );
					if ( null !== $balance ) {
						self::copyStock( $product, $balance );
					}
				}
				$id  = $product->save();
				$ids = array();
				foreach ( $variations as $variation ) {
					$ids[] = $this->saveVariation( $id, 0, $variation['content'], $variation['prices'], $variation['stock'] );
				}
				if ( array() !== $ids ) {
					WC_Product_Variable::sync( $id );
				}

				return array(
					'id'         => $id,
					'variations' => $ids,
				);
			}
		);

		return array(
			'id'         => $result['id'],
			'variations' => $result['variations'],
			'terms'      => $this->created,
		);
	}

	/**
	 * Changes a product's content, and its variations when they're named: the list is complete, so a
	 * variation it leaves out is deleted.
	 *
	 * @param int                                                                                $id         WooCommerce product.
	 * @param array<string, mixed>                                                               $content    Valid content changes.
	 * @param int                                                                                $seller     Seller.
	 * @param Prices|null                                                                        $prices     A simple product's new prices, if they changed.
	 * @param list<array{id: int, content: array<string, mixed>, prices: Prices, stock: Balance|null}>|null $variations The complete list, or null when unchanged.
	 * @return array{id: int, variations: list<int>, deleted: list<int>, terms: list<array{taxonomy: string, term_id: int}>}
	 * @throws DomainError When the product is gone, a variation isn't its own, or WooCommerce refuses a field.
	 */
	public function update( int $id, array $content, int $seller, ?Prices $prices, ?array $variations ): array {
		$result = $this->write(
			$seller,
			function () use ( $id, $content, $prices, $variations ): array {
				$product = $this->product( $id );
				$this->apply( $product, $content );
				if ( null !== $prices && $product->is_type( 'simple' ) ) {
					$this->copyPrices( $product, $prices );
				}
				$product->save();
				$ids     = array();
				$deleted = array();
				if ( null !== $variations ) {
					$existing = array_map( 'intval', $product->get_children() );
					foreach ( $variations as $variation ) {
						if ( $variation['id'] > 0 && ! in_array( $variation['id'], $existing, true ) ) {
							throw DomainError::of( 'mercatus_product_invalid', array( 'field' => 'variations' ) );
						}
						$ids[] = $this->saveVariation( $id, $variation['id'], $variation['content'], $variation['prices'], $variation['stock'] );
					}
					foreach ( array_diff( $existing, $ids ) as $gone ) {
						$child = wc_get_product( $gone );
						if ( $child instanceof WC_Product_Variation ) {
							$child->delete( true );
							$deleted[] = (int) $gone;
						}
					}
					WC_Product_Variable::sync( $id );
				}

				return array(
					'id'         => $id,
					'variations' => $ids,
					'deleted'    => $deleted,
				);
			}
		);

		return array(
			'id'         => $result['id'],
			'variations' => $result['variations'],
			'deleted'    => $result['deleted'],
			'terms'      => $this->created,
		);
	}

	/**
	 * Writes the copies of prices and stock (ARCH-12): a simple product's, and each variation's.
	 *
	 * @param int                                                    $id         WooCommerce product.
	 * @param Prices|null                                            $prices     A simple product's prices.
	 * @param Balance|null                                           $balance    A simple product's stock.
	 * @param array<int, array{prices?: Prices, stock?: Balance|null}> $variations Copies by variation ID.
	 */
	public function copy( int $id, ?Prices $prices, ?Balance $balance, array $variations = array() ): void {
		$this->write(
			null,
			function () use ( $id, $prices, $balance, $variations ): array {
				$product = $this->product( $id );
				if ( $product->is_type( 'simple' ) ) {
					if ( null !== $prices ) {
						$this->copyPrices( $product, $prices );
					}
					if ( null !== $balance ) {
						self::copyStock( $product, $balance );
					}
					$product->save();
				}
				foreach ( $variations as $variation_id => $copies ) {
					$variation = wc_get_product( $variation_id );
					if ( ! $variation instanceof WC_Product_Variation || $variation->get_parent_id() !== $id ) {
						continue;
					}
					if ( isset( $copies['prices'] ) ) {
						$this->copyPrices( $variation, $copies['prices'] );
					}
					if ( isset( $copies['stock'] ) ) {
						self::copyStock( $variation, $copies['stock'] );
					}
					$variation->save();
				}
				if ( array() !== $variations ) {
					WC_Product_Variable::sync( $id );
				}

				return array();
			}
		);
	}

	/**
	 * Sets a product's WooCommerce status (CTL-13).
	 *
	 * @param int    $id     WooCommerce product.
	 * @param string $status publish, private, draft, pending or trash.
	 */
	public function setStatus( int $id, string $status ): void {
		$this->write(
			null,
			function () use ( $id, $status ): array {
				$product = $this->product( $id );
				$current = $product->get_status( 'edit' );
				if ( $current === $status ) {
					return array();
				}
				if ( 'trash' === $status ) {
					$product->delete( false );

					return array();
				}
				if ( 'trash' === $current ) {
					wp_untrash_post( $id );
					$product = $this->product( $id );
				}
				$product->set_status( $status );
				$product->save();

				return array();
			}
		);
	}

	/**
	 * Removes a product Mercatus created but couldn't finish recording (CTL-8).
	 *
	 * @param int $id WooCommerce product.
	 */
	public function discard( int $id ): void {
		$this->write(
			null,
			function () use ( $id ): array {
				$product = wc_get_product( $id );
				if ( $product instanceof WC_Product_Variable ) {
					foreach ( $product->get_children() as $child ) {
						$variation = wc_get_product( $child );
						if ( $variation instanceof WC_Product ) {
							$variation->delete( true );
						}
					}
				}
				if ( $product instanceof WC_Product ) {
					$product->delete( true );
				}

				return array();
			}
		);
	}

	/**
	 * A product's content, for its form and its projection rows; null when it's gone.
	 *
	 * @param int $id WooCommerce product.
	 * @return array<string, mixed>|null
	 */
	public function read( int $id ): ?array {
		$product = wc_get_product( $id );
		if ( ! $product instanceof WC_Product || $product instanceof WC_Product_Variation ) {
			return null;
		}
		$labels     = array();
		$attributes = array();
		foreach ( $product->get_attributes() as $key => $attribute ) {
			if ( ! $attribute instanceof WC_Product_Attribute ) {
				continue;
			}
			$label          = self::label( $attribute );
			$labels[ $key ] = $label;
			$attributes[]   = array(
				'name'   => $label,
				'values' => $attribute->is_taxonomy() ? array_values( array_map( static fn( WP_Term $term ): string => $term->name, $attribute->get_terms() ?? array() ) ) : array_values( array_map( 'strval', $attribute->get_options() ) ),
				'shared' => $attribute->is_taxonomy(),
			);
		}
		$variations = array();
		if ( $product instanceof WC_Product_Variable ) {
			foreach ( $product->get_children() as $child_id ) {
				$child = wc_get_product( $child_id );
				if ( ! $child instanceof WC_Product_Variation ) {
					continue;
				}
				$chosen = array();
				foreach ( $child->get_attributes() as $key => $value ) {
					$taxonomy                                   = str_starts_with( (string) $key, 'pa_' ) ? (string) $key : '';
					$term                                       = '' === $taxonomy ? null : get_term_by( 'slug', (string) $value, $taxonomy );
					$chosen[ $labels[ $key ] ?? (string) $key ] = $term instanceof WP_Term ? $term->name : (string) $value;
				}
				$variations[] = array(
					'id'          => $child->get_id(),
					'attributes'  => $chosen,
					'sku'         => $child->get_sku( 'edit' ),
					'description' => $child->get_description( 'edit' ),
					'virtual'     => $child->get_virtual( 'edit' ),
					'weight'      => (string) $child->get_weight( 'edit' ),
					'length'      => (string) $child->get_length( 'edit' ),
					'width'       => (string) $child->get_width( 'edit' ),
					'height'      => (string) $child->get_height( 'edit' ),
					'enabled'     => 'publish' === $child->get_status( 'edit' ),
				);
			}
		}

		return array(
			'id'                => $product->get_id(),
			'type'              => $product instanceof WC_Product_Variable ? Product::VARIABLE : Product::SIMPLE,
			'name'              => $product->get_name( 'edit' ),
			'description'       => $product->get_description( 'edit' ),
			'short_description' => $product->get_short_description( 'edit' ),
			'sku'               => $product->get_sku( 'edit' ),
			'virtual'           => $product->get_virtual( 'edit' ),
			'weight'            => (string) $product->get_weight( 'edit' ),
			'length'            => (string) $product->get_length( 'edit' ),
			'width'             => (string) $product->get_width( 'edit' ),
			'height'            => (string) $product->get_height( 'edit' ),
			'categories'        => array_values( array_map( 'intval', $product->get_category_ids( 'edit' ) ) ),
			'tags'              => array_values( array_map( array( self::class, 'tagName' ), array_map( 'intval', $product->get_tag_ids( 'edit' ) ) ) ),
			'attributes'        => $attributes,
			'variations'        => $variations,
			'status'            => $product->get_status( 'edit' ),
			'url'               => (string) get_permalink( $product->get_id() ),
		);
	}

	/**
	 * Whether every category exists.
	 *
	 * @param array<mixed> $ids WooCommerce category term IDs.
	 */
	public function categoriesExist( array $ids ): bool {
		foreach ( $ids as $id ) {
			if ( ! is_int( $id ) || ! term_exists( $id, self::CATEGORIES ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * One level of WooCommerce's product categories by name, after a cursor, through WordPress's term query so
	 * a multilingual plugin's language filter still applies (CTL-17).
	 *
	 * @param int         $parent_id   Parent category.
	 * @param string      $search      Text in the name, or ''.
	 * @param int         $limit       Most categories.
	 * @param string|null $after_name  Name the previous page ended with.
	 * @param int|null    $after_id    Term ID the previous page ended with.
	 * @return list<array{id: int, name: string, slug: string, parent_id: int, has_children: bool}>
	 */
	public function categories( int $parent_id, string $search, int $limit, ?string $after_name, ?int $after_id ): array {
		global $wpdb;
		$after = static function ( array $clauses ) use ( $wpdb, $after_name, $after_id ): array {
			if ( null !== $after_name && null !== $after_id ) {
				$clauses['where'] .= (string) $wpdb->prepare( ' AND ( t.name > %s OR ( t.name = %s AND t.term_id > %d ) )', $after_name, $after_name, $after_id );
			}
			$clauses['orderby'] .= ', t.term_id';

			return $clauses;
		};
		add_filter( 'terms_clauses', $after );
		try {
			$terms = get_terms(
				array(
					'taxonomy'   => self::CATEGORIES,
					'parent'     => $parent_id,
					'hide_empty' => false,
					'orderby'    => 'name',
					'order'      => 'ASC',
					'number'     => $limit,
					'name__like' => $search,
				)
			);
		} finally {
			remove_filter( 'terms_clauses', $after );
		}
		$terms   = is_array( $terms ) ? array_values( $terms ) : array();
		$parents = self::parentsAmong( array_map( static fn( WP_Term $term ): int => $term->term_id, $terms ) );

		return array_map(
			static fn( WP_Term $term ): array => array(
				'id'           => $term->term_id,
				'name'         => $term->name,
				'slug'         => $term->slug,
				'parent_id'    => $term->parent,
				'has_children' => isset( $parents[ $term->term_id ] ),
			),
			$terms
		);
	}

	/**
	 * Runs a Mercatus write, marked as Mercatus's own and checked against a seller's SKUs; the terms it creates
	 * are collected in `$created`.
	 *
	 * @template T
	 * @param int|null      $seller Seller, or null to keep the current one.
	 * @param callable(): T $work   Work.
	 * @return T
	 * @throws DomainError When WooCommerce refuses a field.
	 */
	private function write( ?int $seller, callable $work ): mixed {
		$previous     = $this->seller;
		$this->seller = $seller ?? $previous;
		++$this->writing;
		if ( 1 === $this->writing ) {
			$this->created = array();
		}
		try {
			$result = $work();
		} catch ( WC_Data_Exception $error ) {
			$sku = 'product_invalid_sku' === $error->getErrorCode();
			throw DomainError::of( $sku ? 'mercatus_product_sku_taken' : 'mercatus_product_invalid', array( 'field' => $sku ? 'sku' : 'product' ) );
		} finally {
			--$this->writing;
			$this->seller = $previous;
		}
		return $result;
	}

	/**
	 * Applies content to a product or variation.
	 *
	 * @param WC_Product           $product Product.
	 * @param array<string, mixed> $content Valid content.
	 * @throws WC_Data_Exception When WooCommerce refuses a value.
	 */
	private function apply( WC_Product $product, array $content ): void {
		foreach ( $content as $field => $value ) {
			match ( $field ) {
				'name'              => $product->set_name( (string) $value ),
				'description'       => $product->set_description( (string) $value ),
				'short_description' => $product->set_short_description( (string) $value ),
				'sku'               => $product->set_sku( (string) $value ),
				'virtual'           => $product->set_virtual( (bool) $value ),
				'weight'            => $product->set_weight( (string) $value ),
				'length'            => $product->set_length( (string) $value ),
				'width'             => $product->set_width( (string) $value ),
				'height'            => $product->set_height( (string) $value ),
				'categories'        => $product->set_category_ids( is_array( $value ) ? array_map( 'intval', $value ) : array() ),
				'tags'              => $product->set_tag_ids( $this->tagIds( is_array( $value ) ? array_map( 'strval', $value ) : array() ) ),
				'attributes'        => $product->set_attributes( $this->attributes( is_array( $value ) ? $value : array() ) ),
				default             => null,
			};
		}
	}

	/**
	 * Saves one variation with its content and copies, and returns its ID.
	 *
	 * @param int                  $parent_id Variable product.
	 * @param int                  $id        Variation ID, or 0 for a new one.
	 * @param array<string, mixed> $content   Valid variation content.
	 * @param Prices               $prices    Prices.
	 * @param Balance|null         $stock     Stock, for a new variation.
	 * @throws WC_Data_Exception When WooCommerce refuses a value.
	 */
	private function saveVariation( int $parent_id, int $id, array $content, Prices $prices, ?Balance $stock ): int {
		$variation = $id > 0 ? wc_get_product( $id ) : new WC_Product_Variation();
		if ( ! $variation instanceof WC_Product_Variation ) {
			throw DomainError::of( 'mercatus_product_invalid', array( 'field' => 'variations' ) );
		}
		$variation->set_parent_id( $parent_id );
		if ( isset( $content['attributes'] ) && is_array( $content['attributes'] ) ) {
			$variation->set_attributes( $this->chosen( $parent_id, $content['attributes'] ) );
		}
		$this->apply( $variation, array_intersect_key( $content, array_flip( array( 'sku', 'description', 'virtual', 'weight', 'length', 'width', 'height' ) ) ) );
		if ( isset( $content['enabled'] ) ) {
			$variation->set_status( true === $content['enabled'] ? 'publish' : 'private' );
		} elseif ( 0 === $id ) {
			$variation->set_status( 'publish' );
		}
		$this->copyPrices( $variation, $prices );
		if ( null !== $stock ) {
			self::copyStock( $variation, $stock );
		}

		return $variation->save();
	}

	/**
	 * Term IDs for tag names, found ignoring case or created (CTL-21).
	 *
	 * @param array<string> $names Tag names.
	 * @return list<int>
	 */
	private function tagIds( array $names ): array {
		return array_values( array_map( fn( string $name ): int => $this->term( $name, self::TAGS ), $names ) );
	}

	/**
	 * WooCommerce attributes from a vendor's: an attribute named like a shared one uses it, its values found
	 * or added; any other belongs to the product alone (CTL-21). All are used for variations (CAT-2).
	 *
	 * @param array<mixed> $attributes Valid attributes: name and values.
	 * @return array<string, WC_Product_Attribute>
	 */
	private function attributes( array $attributes ): array {
		$result   = array();
		$position = 0;
		foreach ( $attributes as $attribute ) {
			if ( ! is_array( $attribute ) ) {
				continue;
			}
			$name   = (string) ( $attribute['name'] ?? '' );
			$values = array_map( 'strval', is_array( $attribute['values'] ?? null ) ? $attribute['values'] : array() );
			$shared = self::sharedAttribute( $name );
			$object = new WC_Product_Attribute();
			if ( null !== $shared ) {
				$taxonomy = wc_attribute_taxonomy_name( $shared->attribute_name );
				$object->set_id( (int) $shared->attribute_id );
				$object->set_name( $taxonomy );
				$object->set_options( array_map( fn( string $value ): int => $this->term( $value, $taxonomy ), $values ) );
			} else {
				$object->set_id( 0 );
				$object->set_name( $name );
				$object->set_options( $values );
			}
			$object->set_position( $position++ );
			$object->set_visible( true );
			$object->set_variation( true );
			$result[ sanitize_title( $object->get_name() ) ] = $object;
		}

		return $result;
	}

	/**
	 * A variation's attribute values in WooCommerce's form: a shared attribute's by its term slug, the
	 * product's own by its value.
	 *
	 * @param int                   $parent_id Variable product.
	 * @param array<string, string> $chosen    Value by attribute name.
	 * @return array<string, string>
	 */
	private function chosen( int $parent_id, array $chosen ): array {
		$parent = wc_get_product( $parent_id );
		$by     = array();
		foreach ( $parent instanceof WC_Product ? $parent->get_attributes() : array() as $key => $attribute ) {
			if ( $attribute instanceof WC_Product_Attribute ) {
				$by[ mb_strtolower( self::label( $attribute ) ) ] = array( (string) $key, $attribute );
			}
		}
		$values = array();
		foreach ( $chosen as $name => $value ) {
			$match = $by[ mb_strtolower( (string) $name ) ] ?? null;
			if ( null === $match ) {
				throw DomainError::of( 'mercatus_product_invalid', array( 'field' => 'variations' ) );
			}
			list( $key, $attribute ) = $match;
			if ( $attribute->is_taxonomy() ) {
				$term           = get_term_by( 'name', $value, $attribute->get_name() );
				$values[ $key ] = $term instanceof WP_Term ? $term->slug : sanitize_title( $value );
			} else {
				$values[ $key ] = $value;
			}
		}

		return $values;
	}

	/**
	 * A term's ID by name, ignoring case, created when missing and recorded as created.
	 *
	 * @param string $name     Name.
	 * @param string $taxonomy Taxonomy.
	 */
	private function term( string $name, string $taxonomy ): int {
		$term = get_term_by( 'name', $name, $taxonomy );
		if ( $term instanceof WP_Term ) {
			return $term->term_id;
		}
		$created = wp_insert_term( $name, $taxonomy );
		if ( $created instanceof WP_Error ) {
			$existing = $created->get_error_data( 'term_exists' );
			if ( is_numeric( $existing ) ) {
				return (int) $existing;
			}
			throw DomainError::of( 'mercatus_product_invalid', array( 'field' => self::TAGS === $taxonomy ? 'tags' : 'attributes' ) );
		}
		$this->created[] = array(
			'taxonomy' => $taxonomy,
			'term_id'  => (int) $created['term_id'],
		);

		return (int) $created['term_id'];
	}

	/**
	 * An attribute's name as vendors see it: a shared attribute's label, read by its ID so it doesn't depend on
	 * the attributes WooCommerce registered when the request started; the product's own attribute's name.
	 *
	 * @param WC_Product_Attribute $attribute Attribute.
	 */
	private static function label( WC_Product_Attribute $attribute ): string {
		if ( ! $attribute->is_taxonomy() ) {
			return $attribute->get_name();
		}
		$shared = wc_get_attribute( $attribute->get_id() );

		return null === $shared ? wc_attribute_label( $attribute->get_name() ) : (string) $shared->name;
	}

	/**
	 * A tag's name.
	 *
	 * @param int $id Tag term ID.
	 */
	private static function tagName( int $id ): string {
		$name = get_term_field( 'name', $id, self::TAGS );

		return is_string( $name ) ? $name : '';
	}

	/**
	 * The shared attribute with a name or slug, ignoring case.
	 *
	 * @param string $name Attribute name.
	 */
	private static function sharedAttribute( string $name ): ?stdClass {
		$wanted = mb_strtolower( trim( $name ) );
		foreach ( wc_get_attribute_taxonomies() as $attribute ) {
			if ( $attribute instanceof stdClass && ( mb_strtolower( (string) $attribute->attribute_label ) === $wanted || sanitize_title( $name ) === (string) $attribute->attribute_name ) ) {
				return $attribute;
			}
		}

		return null;
	}

	/**
	 * The terms among some that have children, in one query.
	 *
	 * @param list<int> $ids Category term IDs.
	 * @return array<int, true>
	 */
	private static function parentsAmong( array $ids ): array {
		if ( array() === $ids ) {
			return array();
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- a read of WordPress's term index; integer IDs appended after preparing.
		$parents = $wpdb->get_col( (string) $wpdb->prepare( 'SELECT DISTINCT parent FROM %i WHERE taxonomy = %s AND parent IN (', $wpdb->term_taxonomy, self::CATEGORIES ) . implode( ',', array_map( 'intval', $ids ) ) . ')' );

		return array_fill_keys( array_map( 'intval', $parents ), true );
	}

	/**
	 * A product by ID.
	 *
	 * @param int $id WooCommerce product.
	 * @throws DomainError When it's gone.
	 */
	private function product( int $id ): WC_Product {
		$product = wc_get_product( $id );
		if ( ! $product instanceof WC_Product ) {
			throw DomainError::of( 'mercatus_product_unknown' );
		}

		return $product;
	}

	/**
	 * Copies prices onto a product or variation (ARCH-12), with the price active now, so a scheduled sale changes WooCommerce's price when it starts or ends.
	 *
	 * @param WC_Product $product Product.
	 * @param Prices     $prices  Prices.
	 */
	private function copyPrices( WC_Product $product, Prices $prices ): void {
		$active = $this->resolver->resolve( $prices, $this->clock->now() );
		$product->set_price( null === $active ? '' : $active->toString() );
		$product->set_regular_price( null === $prices->regular ? '' : $prices->regular->toString() );
		$product->set_sale_price( null === $prices->sale ? '' : $prices->sale->toString() );
		$product->set_date_on_sale_from( null === $prices->saleStartsAt ? null : (string) $prices->saleStartsAt->getTimestamp() );
		$product->set_date_on_sale_to( null === $prices->saleEndsAt ? null : (string) $prices->saleEndsAt->getTimestamp() );
	}

	/**
	 * Copies stock onto a product or variation (ARCH-12).
	 *
	 * @param WC_Product $product Product.
	 * @param Balance    $balance Stock.
	 */
	private static function copyStock( WC_Product $product, Balance $balance ): void {
		$product->set_manage_stock( $balance->manageStock );
		$product->set_stock_quantity( $balance->manageStock ? $balance->quantity->toInt() : null );
		$product->set_backorders( $balance->backorders );
		$product->set_stock_status( $balance->stockStatus );
	}
}
