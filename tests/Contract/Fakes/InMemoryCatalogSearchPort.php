<?php
/**
 * In-memory CatalogSearchPort for tests.
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Contract\Fakes;

use Mercatus\Core\Contracts\Catalog\CatalogQuery;
use Mercatus\Core\Contracts\Catalog\CatalogSearchPort;
use Mercatus\Core\Contracts\Catalog\CatalogSearchResult;

/**
 * Searches indexed products in ID order with a keyset cursor (API-1) and exact facet counts.
 */
final class InMemoryCatalogSearchPort implements CatalogSearchPort {

	/**
	 * Indexed products by catalog product ID.
	 *
	 * @var array<int, array{title: string, category_id: int, attributes: array<int, list<int>>}>
	 */
	private array $indexed = array();

	/**
	 * Creates the port.
	 *
	 * @param array<int, array{title: string, category_id: int, attributes: array<int, list<int>>}> $products Backing data by catalog product ID.
	 */
	public function __construct( private array $products ) {}

	public function search( CatalogQuery $query ): CatalogSearchResult {
		$matches = array_filter( $this->indexed, fn( array $product ): bool => $this->matches( $product, $query ) );
		ksort( $matches );

		$facets = array();
		foreach ( $matches as $product ) {
			foreach ( $product['attributes'] as $attribute_id => $value_ids ) {
				foreach ( $value_ids as $value_id ) {
					$facets[ $attribute_id ][ $value_id ] = ( $facets[ $attribute_id ][ $value_id ] ?? 0 ) + 1;
				}
			}
		}

		$after = null === $query->cursor ? 0 : (int) $query->cursor;
		$ids   = array_values( array_filter( array_keys( $matches ), static fn( int $id ): bool => $id > $after ) );
		$page  = array_slice( $ids, 0, $query->perPage );
		$more  = count( $ids ) > $query->perPage;

		return new CatalogSearchResult( $page, $more ? (string) end( $page ) : null, $facets );
	}

	public function index( array $catalog_product_ids ): void {
		foreach ( $catalog_product_ids as $id ) {
			if ( isset( $this->products[ $id ] ) ) {
				$this->indexed[ $id ] = $this->products[ $id ];
			}
		}
	}

	public function remove( array $catalog_product_ids ): void {
		foreach ( $catalog_product_ids as $id ) {
			unset( $this->indexed[ $id ] );
		}
	}

	/**
	 * Whether a product matches the text, category and every attribute filter.
	 *
	 * @param array{title: string, category_id: int, attributes: array<int, list<int>>} $product Product.
	 * @param CatalogQuery                                                             $query   Query.
	 */
	private function matches( array $product, CatalogQuery $query ): bool {
		if ( '' !== $query->text && false === stripos( $product['title'], $query->text ) ) {
			return false;
		}
		if ( null !== $query->categoryId && $product['category_id'] !== $query->categoryId ) {
			return false;
		}
		foreach ( $query->attributeValues as $attribute_id => $value_ids ) {
			if ( array() === array_intersect( $product['attributes'][ $attribute_id ] ?? array(), $value_ids ) ) {
				return false;
			}
		}

		return true;
	}
}
