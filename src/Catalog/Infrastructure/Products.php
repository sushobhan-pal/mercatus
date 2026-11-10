<?php
/**
 * core.catalog's commands and queries (CTL-1 to CTL-10, CTL-19 to CTL-22, CTL-26).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Catalog\Infrastructure;

use DateTimeImmutable;
use Mercatus\Core\Catalog\Application\CatalogAccess;
use Mercatus\Core\Catalog\Application\CatalogDirectory;
use Mercatus\Core\Catalog\Application\CatalogFields;
use Mercatus\Core\Catalog\Application\CatalogPolicy;
use Mercatus\Core\Catalog\Application\CatalogSettings;
use Mercatus\Core\Catalog\Application\ProductSerializer;
use Mercatus\Core\Catalog\Domain\Balance;
use Mercatus\Core\Catalog\Domain\Offer;
use Mercatus\Core\Catalog\Domain\OfferVariation;
use Mercatus\Core\Catalog\Domain\Prices;
use Mercatus\Core\Catalog\Domain\Product;
use Mercatus\Core\Catalog\Domain\ProductInput;
use Mercatus\Core\Catalog\Domain\ProductLifecycle;
use Mercatus\Core\Catalog\Domain\WooStatus;
use Mercatus\Core\Contracts\Cache\CachePort;
use Mercatus\Core\Contracts\Identity\AccessDecision;
use Mercatus\Core\Contracts\Identity\CurrentActor;
use Mercatus\Core\Contracts\Settings\SettingsReader;
use Mercatus\Core\Infrastructure\Content\RichText;
use Mercatus\Core\Shared\Audit\AuditLog;
use Mercatus\Core\Shared\Clock\Clock;
use Mercatus\Core\Shared\Errors\DomainError;
use Mercatus\Core\Shared\Events\EventLane;
use Mercatus\Core\Shared\Events\EventPublisher;
use Mercatus\Core\Shared\Http\ListRequest;
use Mercatus\Core\Shared\Id\Uuid;
use Mercatus\Core\Shared\Money\Decimal;
use Mercatus\Core\Shared\Persistence\TransactionRunner;
use Mercatus\Core\Shared\Projection\ProjectionRunner;
use Mercatus\Core\Store\Infrastructure\Stores;
use Throwable;

/**
 * Creating and changing content writes WooCommerce first, outside the transaction, then commits Mercatus's
 * rows, the projection rows, the audit entry and the events together (CTL-8, ARCH-10); a creation that can't
 * be recorded is removed again. State changes and stock commit Mercatus first and then write WooCommerce's
 * status or stock copy, which the reconciler re-applies if that write fails. Every change bumps the product's
 * version (CTL-7) and, after commit, its cache revision.
 */
final class Products implements CatalogDirectory {

	private const GROUP = 'mercatus_offers';

	private const TTL = 3600;

	/**
	 * Creates the service.
	 *
	 * @param ProductRepository      $products     Catalog products.
	 * @param OfferRepository        $offers       Offers.
	 * @param InventoryRepository    $inventory    Stock.
	 * @param AvailabilityRepository $availability The projection's table.
	 * @param AvailabilityProjector  $projector    The projection.
	 * @param ProjectionRunner       $runner       The projection runner.
	 * @param WooCatalog             $woo          WooCommerce.
	 * @param CatalogPolicy          $policy       Who may do what.
	 * @param TransactionRunner      $transactions Transactions.
	 * @param EventPublisher         $events       Events.
	 * @param AuditLog               $audit        Audit log.
	 * @param CurrentActor           $actors       The current actor.
	 * @param SettingsReader         $settings     Settings.
	 * @param Clock                  $clock        Clock.
	 * @param CachePort              $cache        Cache.
	 * @param Stores                 $stores       Stores, for the seller's location and openness.
	 */
	public function __construct(
		private ProductRepository $products,
		private OfferRepository $offers,
		private InventoryRepository $inventory,
		private AvailabilityRepository $availability,
		private AvailabilityProjector $projector,
		private ProjectionRunner $runner,
		private WooCatalog $woo,
		private CatalogPolicy $policy,
		private TransactionRunner $transactions,
		private EventPublisher $events,
		private AuditLog $audit,
		private CurrentActor $actors,
		private SettingsReader $settings,
		private Clock $clock,
		private CachePort $cache,
		private Stores $stores,
	) {}

	public function mine( ListRequest $request, string $search ): array {
		$rows = $this->availability->vendorPage( $this->vendorId(), $request, (string) ( $request->filters['status'] ?? '' ), $search );

		return array_map(
			static fn( array $row ): array => array(
				'id'      => (int) $row['catalog_product_id'],
				'version' => (int) $row['version'],
				'product' => ProductSerializer::listItem( $row ),
			),
			$rows
		);
	}

	public function create( array $input ): array {
		$vendor_id = $this->vendorId();
		$location  = $this->location( $vendor_id );
		$type      = $input['type'] ?? null;
		if ( ! is_string( $type ) || ! in_array( $type, Product::TYPES, true ) ) {
			throw self::invalid( 'type' );
		}
		$valid      = ProductInput::validate( $this->clean( $input ), $type, true );
		$prices     = self::merge( Prices::none(), $valid['prices'] ?? array() );
		$variations = $valid['variations'] ?? array();
		if ( Product::VARIABLE === $type ) {
			ProductInput::assertVariations( $variations, $valid['attributes'] ?? array() );
		}
		$this->assertCategories( $valid );
		$this->assertSkus( $vendor_id, 0, (string) ( $valid['sku'] ?? '' ), $variations );
		$stock = Product::SIMPLE === $type ? self::balance( $location, $valid['stock'] ?? ProductInput::stock( array() ) ) : null;
		$specs = array();
		foreach ( $variations as $variation ) {
			$specs[] = array(
				'content' => self::variationContent( $variation ),
				'prices'  => self::merge( Prices::none(), $variation['prices'] ?? array() ),
				'stock'   => self::balance( $location, $variation['stock'] ?? ProductInput::stock( array() ) ),
			);
		}
		$created = $this->woo->create( $type, self::content( $valid ), $vendor_id, 'draft', $prices, $stock, $specs );
		$woo_id  = $created['id'];
		try {
			$product_id = (int) $this->transactions->run(
				function () use ( $woo_id, $vendor_id, $location, $type, $valid, $prices, $stock, $specs, $created ): int {
					$now        = $this->clock->now();
					$product_id = $this->products->insert( $woo_id, Product::SCOPE_VENDOR, $vendor_id, $type, Product::DRAFT, self::primaryCategory( $valid, null ), $now );
					$offer_id   = $this->offers->insert( $product_id, $vendor_id, Offer::PAUSED, $prices, $now );
					$this->offers->placeAt( $offer_id, $location, $now );
					if ( null !== $stock ) {
						$this->openBalance( self::placed( $stock, $offer_id, 0 ), $now );
					}
					$all_prices = array( $prices );
					foreach ( $created['variations'] as $index => $variation_id ) {
						$spec = $specs[ $index ];
						$this->offers->saveVariation( $offer_id, $variation_id, self::variationStatus( $spec['content'], null ), $spec['prices'], $now );
						$this->openBalance( self::placed( $spec['stock'], $offer_id, $variation_id ), $now );
						$all_prices[] = $spec['prices'];
					}
					$this->events->publish(
						'catalog.product.created',
						'product',
						$woo_id,
						1,
						array(
							'product_id'   => $woo_id,
							'vendor_id'    => $vendor_id,
							'product_type' => $type,
						)
					);
					$this->audit->record( 'product.created', 'product', $woo_id, array( 'product_type' => $type ) );
					$this->termsCreated( $created['terms'], $woo_id, $vendor_id );
					$this->scheduleSales( $woo_id, $product_id, $all_prices, $now );
					$this->runner->rebuild( $this->projector, $product_id, false );

					return $product_id;
				}
			);
		} catch ( Throwable $error ) {
			$this->woo->discard( $woo_id );
			throw $error;
		}
		$this->changed( $product_id );

		return $this->get( $woo_id );
	}

	public function get( int $id ): array {
		$bundle = $this->bundle( $id );
		$this->allow( $bundle['product'], CatalogPolicy::EDIT );

		return $this->full( $bundle );
	}

	public function update( int $id, array $changes, int $expected ): array {
		$bundle  = $this->bundle( $id );
		$product = $bundle['product'];
		$offer   = $bundle['offer'];
		$this->allow( $product, CatalogPolicy::EDIT );
		if ( ! ProductLifecycle::editable( $product->status ) ) {
			throw DomainError::of( 'mercatus_product_not_editable' );
		}
		if ( $product->version !== $expected ) {
			throw self::conflict( $product );
		}
		$valid   = ProductInput::validate( $this->clean( $changes ), $product->type, false );
		$content = $this->woo->read( $product->wooProductId ) ?? throw self::unknown();
		$prices  = isset( $valid['prices'] ) && is_array( $valid['prices'] ) ? self::merge( $offer->prices, $valid['prices'] ) : null;
		$terms   = array();
		foreach ( $bundle['variations'] as $variation ) {
			$terms[ $variation->variationId ] = $variation;
		}
		$location = $this->location( $offer->vendorId );
		$specs    = null;
		if ( isset( $valid['variations'] ) && is_array( $valid['variations'] ) ) {
			ProductInput::assertVariations( $valid['variations'], $valid['attributes'] ?? $content['attributes'] );
			$specs = array();
			foreach ( $valid['variations'] as $variation ) {
				$own     = $terms[ $variation['id'] ] ?? null;
				$specs[] = array(
					'id'      => (int) $variation['id'],
					'content' => self::variationContent( $variation ),
					'prices'  => self::merge( null === $own ? Prices::none() : $own->prices, $variation['prices'] ?? array() ),
					'stock'   => 0 === $variation['id'] ? self::balance( $location, $variation['stock'] ?? ProductInput::stock( array() ) ) : null,
				);
			}
		} elseif ( isset( $valid['attributes'] ) ) {
			throw self::invalid( 'variations' );
		}
		$this->assertCategories( $valid );
		$this->assertSkus( $offer->vendorId, $product->id, (string) ( $valid['sku'] ?? '' ), $valid['variations'] ?? array() );
		$result = $this->woo->update( $product->wooProductId, self::content( $valid ), $offer->vendorId, $prices, $specs );
		$fields = self::changedFields( $valid );
		$this->transactions->run(
			function () use ( $product, $offer, $expected, $valid, $prices, $specs, $terms, $result, $fields, $bundle ): void {
				$now = $this->clock->now();
				if ( ! $this->products->touch( $product->id, $expected, self::primaryCategory( $valid, $product->primaryCategoryId ), $now ) ) {
					throw self::conflict( $product );
				}
				$all_prices = array( $prices ?? $offer->prices );
				if ( null !== $prices && ! $prices->equals( $offer->prices ) ) {
					$this->offers->update( $offer->id, $offer->status, $prices, $now );
				}
				if ( null !== $specs ) {
					foreach ( $result['variations'] as $index => $variation_id ) {
						$spec = $specs[ $index ];
						$this->offers->saveVariation( $offer->id, $variation_id, self::variationStatus( $spec['content'], $terms[ $variation_id ] ?? null ), $spec['prices'], $now );
						if ( null !== $spec['stock'] && ! isset( $bundle['balances'][ $variation_id ] ) ) {
							$this->openBalance( self::placed( $spec['stock'], $offer->id, $variation_id ), $now );
						}
						$all_prices[] = $spec['prices'];
					}
					foreach ( $result['deleted'] as $variation_id ) {
						$old = $terms[ $variation_id ] ?? null;
						$this->offers->saveVariation( $offer->id, $variation_id, OfferVariation::DELETED, null === $old ? Prices::none() : $old->prices, $now );
					}
				}
				$this->events->publish(
					'catalog.product.updated',
					'product',
					$product->wooProductId,
					$expected + 1,
					array(
						'product_id' => $product->wooProductId,
						'vendor_id'  => $offer->vendorId,
						'fields'     => $fields,
					)
				);
				$this->audit->record( 'product.updated', 'product', $product->wooProductId, array( 'fields' => implode( ',', $fields ) ) );
				$this->termsCreated( $result['terms'], $product->wooProductId, $offer->vendorId );
				$this->scheduleSales( $product->wooProductId, $product->id, $all_prices, $now );
				$this->runner->rebuild( $this->projector, $product->id, false );
			}
		);
		$this->changed( $product->id );

		return $this->get( $id );
	}

	public function trash( int $id ): array {
		return $this->transition(
			$id,
			CatalogPolicy::EDIT,
			static fn( Product $product, Offer $offer ): array => array( Product::TRASHED, $offer->status, $product->statusReason, 'catalog.product.trashed' )
		);
	}

	public function publish( int $id ): array {
		return $this->transition(
			$id,
			CatalogPolicy::EDIT,
			function ( Product $product, Offer $offer, array $bundle ): array {
				$next = ProductLifecycle::published( $product->status, $this->settings->bool( CatalogSettings::REVIEW_NEW_PRODUCTS ) );
				self::assertComplete( $product, $offer, $bundle['variations'] );

				return array( $next, Offer::ACTIVE, Product::PENDING === $next ? '' : $product->statusReason, Product::PENDING === $next ? 'catalog.product.submitted' : 'catalog.product.published' );
			}
		);
	}

	public function unpublish( int $id ): array {
		return $this->transition(
			$id,
			CatalogPolicy::EDIT,
			static fn( Product $product ): array => array( ProductLifecycle::unpublished( $product->status ), Offer::PAUSED, $product->statusReason, 'catalog.product.unpublished' )
		);
	}

	public function adjustInventory( int $offer_id, array $items, int $expected ): array {
		$offer   = $this->offers->byId( $offer_id );
		$product = null === $offer ? null : $this->products->byId( $offer->catalogProductId );
		if ( null === $offer || null === $product || Offer::DELETED === $offer->status ) {
			throw self::unknown();
		}
		$this->allow( $product, CatalogPolicy::EDIT );
		if ( $product->version !== $expected ) {
			throw self::conflict( $product );
		}
		$balances = $this->inventory->forOffer( $offer->id );
		$changes  = array();
		$reasons  = array();
		foreach ( $items as $item ) {
			list( $variation_id, $balance, $reason ) = self::adjusted( $item, $balances );
			$changes[ $variation_id ]                = $balance;
			$reasons[ $variation_id ]                = $reason;
		}
		if ( array() === $changes ) {
			throw DomainError::of( 'mercatus_inventory_invalid', array( 'field' => 'items' ) );
		}
		$actor = $this->actors->identity()->ref();
		$this->transactions->run(
			function () use ( $product, $offer, $expected, $balances, $changes, $reasons, $actor ): void {
				$now = $this->clock->now();
				foreach ( $changes as $variation_id => $balance ) {
					$old = $balances[ $variation_id ];
					if ( ! $this->inventory->update( $balance, $now ) ) {
						throw self::conflict( $product );
					}
					$this->inventory->move( Uuid::v7( $this->clock ), $old->id, $balance->quantity->subtract( $old->quantity ), $balance->quantity, $reasons[ $variation_id ], $actor, $now );
				}
				if ( ! $this->products->touch( $product->id, $expected, $product->primaryCategoryId, $now ) ) {
					throw self::conflict( $product );
				}
				$this->events->publish(
					'catalog.inventory.adjusted',
					'product',
					$product->wooProductId,
					$expected + 1,
					array(
						'product_id' => $product->wooProductId,
						'offer_id'   => $offer->id,
						'vendor_id'  => $offer->vendorId,
						'variations' => array_keys( $changes ),
					)
				);
				$this->audit->record( 'inventory.adjusted', 'product', $product->wooProductId, array( 'variations' => implode( ',', array_keys( $changes ) ) ) );
				$this->runner->rebuild( $this->projector, $product->id, false );
			}
		);
		$copies = array();
		foreach ( $changes as $variation_id => $balance ) {
			if ( $variation_id > 0 ) {
				$copies[ $variation_id ] = array( 'stock' => $balance );
			}
		}
		$this->woo->copy( $product->wooProductId, null, $changes[0] ?? null, $copies );
		$this->changed( $product->id );

		return $this->get( $product->wooProductId );
	}

	public function moderation( ListRequest $request, int $vendor_id ): array {
		$actor = $this->actors->identity();
		if ( ! $actor->isAdministrator() && ! $actor->holds( CatalogAccess::MODERATE ) ) {
			throw DomainError::of( 'mercatus_product_moderation_not_allowed' );
		}
		$products = $this->products->moderationPage( $request, (string) ( $request->filters['status'] ?? '' ), $vendor_id );
		$rows     = $this->availability->productRows( array_map( static fn( Product $product ): int => $product->id, $products ) );
		$items    = array();
		foreach ( $products as $product ) {
			if ( AccessDecision::Allowed === $this->policy->decide( $actor, CatalogPolicy::MODERATE, $product ) ) {
				$items[] = array(
					'id'      => $product->id,
					'version' => $product->version,
					'product' => ProductSerializer::moderationItem( $product, $rows[ $product->id ] ?? null ),
				);
			}
		}

		return $items;
	}

	public function moderated( int $id ): array {
		$bundle = $this->bundle( $id );
		$this->allow( $bundle['product'], CatalogPolicy::MODERATE );

		return $this->full( $bundle );
	}

	public function approve( int $id ): array {
		return $this->transition(
			$id,
			CatalogPolicy::MODERATE,
			static fn( Product $product, Offer $offer ): array => array( in_array( $product->status, array( Product::PENDING, Product::TAKEN_DOWN ), true ) ? Product::APPROVED : throw self::notAllowed( $product, 'approve' ), $offer->status, '', 'catalog.product.approved' )
		);
	}

	public function reject( int $id, string $reason ): array {
		$reason = self::reason( $reason );

		return $this->transition(
			$id,
			CatalogPolicy::MODERATE,
			static fn( Product $product, Offer $offer ): array => array( Product::PENDING === $product->status ? Product::REJECTED : throw self::notAllowed( $product, 'reject' ), $offer->status, $reason, 'catalog.product.rejected' )
		);
	}

	public function takeDown( int $id, string $reason ): array {
		$reason = self::reason( $reason );

		return $this->transition(
			$id,
			CatalogPolicy::MODERATE,
			static fn( Product $product, Offer $offer ): array => array( Product::APPROVED === $product->status ? Product::TAKEN_DOWN : throw self::notAllowed( $product, 'take_down' ), $offer->status, $reason, 'catalog.product.taken_down' )
		);
	}

	public function categories( int $parent_id, string $search, ListRequest $request ): array {
		$after = null === $request->afterId ? null : (string) $request->afterValue;

		return array_map(
			static fn( array $category ): array => array(
				'id'       => $category['id'],
				'version'  => 1,
				'name'     => $category['name'],
				'category' => $category,
			),
			$this->woo->categories( $parent_id, $search, $request->perPage + 1, $after, $request->afterId )
		);
	}

	/**
	 * Re-applies a product's copies and rebuilds its rows when a scheduled sale starts or ends (CTL-22).
	 *
	 * @param int $woo_id WooCommerce product.
	 */
	public function refreshPrices( int $woo_id ): void {
		$product = $this->products->byWooId( $woo_id );
		if ( null === $product || Product::DELETED === $product->status ) {
			return;
		}
		foreach ( $this->offers->forProduct( $product->id ) as $offer ) {
			$copies = array();
			foreach ( $this->offers->variations( $offer->id ) as $variation ) {
				$copies[ $variation->variationId ] = array( 'prices' => $variation->prices );
			}
			$this->woo->copy( $woo_id, Product::SIMPLE === $product->type ? $offer->prices : null, null, $copies );
		}
		$this->runner->rebuild( $this->projector, $product->id );
	}

	/**
	 * Moves a product to the state an action decides, then applies WooCommerce's status (CTL-13).
	 *
	 * @param int                                                                                      $id     WooCommerce product.
	 * @param string                                                                                   $action CatalogPolicy action.
	 * @param callable(Product, Offer, array<string, mixed>): array{0: string, 1: string, 2: string, 3: string} $decide Next state, offer state, reason and event.
	 * @return array{product: array<string, mixed>, version: int}
	 * @throws DomainError When the actor may not, the state doesn't allow it, or it changed meanwhile.
	 */
	private function transition( int $id, string $action, callable $decide ): array {
		$bundle  = $this->bundle( $id );
		$product = $bundle['product'];
		$offer   = $bundle['offer'];
		$this->allow( $product, $action );
		list( $next, $offer_status, $reason, $event ) = $decide( $product, $offer, $bundle );
		if ( $next === $product->status && $offer_status === $offer->status && $reason === $product->statusReason ) {
			return $this->full( $bundle );
		}
		if ( $next !== $product->status ) {
			ProductLifecycle::machine()->assert( $product->status, $next );
		}
		$this->transactions->run(
			function () use ( $product, $offer, $next, $offer_status, $reason, $event ): void {
				$now = $this->clock->now();
				if ( ! $this->products->setStatus( $product->id, $product->version, $next, $reason, $now ) ) {
					throw self::conflict( $product );
				}
				if ( $offer_status !== $offer->status ) {
					$this->offers->update( $offer->id, $offer_status, $offer->prices, $now );
				}
				$this->events->publish(
					$event,
					'product',
					$product->wooProductId,
					$product->version + 1,
					array(
						'product_id' => $product->wooProductId,
						'vendor_id'  => $offer->vendorId,
						'status'     => ProductLifecycle::visible( $next, $offer_status ),
					)
				);
				$this->audit->record( substr( $event, strlen( 'catalog.' ) ), 'product', $product->wooProductId, array( 'status' => $next ) );
				$this->runner->rebuild( $this->projector, $product->id, false );
			}
		);
		$this->woo->setStatus( $product->wooProductId, WooStatus::for( $next, $offer_status, $this->storeOpen( $offer->vendorId ) ) );
		$this->changed( $product->id );

		return $this->full( $this->bundle( $id ) );
	}

	/**
	 * A product with its offer, variation terms and stock, from the cache or the database.
	 *
	 * @param int $woo_id WooCommerce product.
	 * @return array{product: Product, offer: Offer, variations: list<OfferVariation>, balances: array<int, Balance>}
	 * @throws DomainError When there's no such product (mercatus_product_unknown).
	 */
	private function bundle( int $woo_id ): array {
		$product_id = $this->productId( $woo_id );
		$data       = $product_id > 0 ? $this->cache->remember(
			self::GROUP,
			'product:' . $product_id . ':r' . $this->cache->revision( AvailabilityProjector::productScope( $product_id ) ),
			self::TTL,
			fn(): ?array => $this->load( $product_id )
		) : null;
		if ( ! is_array( $data ) ) {
			throw self::unknown();
		}
		$balances = array();
		foreach ( $data['balances'] as $row ) {
			$balance                           = Balance::fromArray( $row );
			$balances[ $balance->variationId ] = $balance;
		}

		return array(
			'product'    => Product::fromArray( $data['product'] ),
			'offer'      => Offer::fromArray( $data['offer'] ),
			'variations' => array_map( static fn( array $row ): OfferVariation => OfferVariation::fromArray( $row ), $data['variations'] ),
			'balances'   => $balances,
		);
	}

	/**
	 * A product's rows as cacheable arrays.
	 *
	 * @param int $product_id Catalog product.
	 * @return array{product: array<string, mixed>, offer: array<string, mixed>, variations: list<array<string, mixed>>, balances: list<array<string, mixed>>}|null
	 */
	private function load( int $product_id ): ?array {
		$product = $this->products->byId( $product_id );
		$offer   = null === $product ? null : ( $this->offers->forProduct( $product->id )[0] ?? null );
		if ( null === $product || null === $offer || Product::DELETED === $product->status ) {
			return null;
		}

		return array(
			'product'    => $product->toArray(),
			'offer'      => $offer->toArray(),
			'variations' => array_map( static fn( OfferVariation $variation ): array => $variation->toArray(), $this->offers->variations( $offer->id ) ),
			'balances'   => array_values( array_map( static fn( Balance $balance ): array => $balance->toArray(), $this->inventory->forOffer( $offer->id ) ) ),
		);
	}

	/**
	 * A WooCommerce product's catalog ID, through a map that never changes once made; 0 when it has none.
	 *
	 * @param int $woo_id WooCommerce product.
	 */
	private function productId( int $woo_id ): int {
		$key = 'woo:' . $woo_id;
		$id  = $this->cache->get( self::GROUP, $key );
		if ( is_int( $id ) && $id > 0 ) {
			return $id;
		}
		$id = $this->products->byWooId( $woo_id )->id ?? 0;
		if ( $id > 0 ) {
			$this->cache->set( self::GROUP, $key, $id, self::TTL );
		}

		return $id;
	}

	/**
	 * Every field, with the version.
	 *
	 * @param array{product: Product, offer: Offer, variations: list<OfferVariation>, balances: array<int, Balance>} $bundle The product.
	 * @return array{product: array<string, mixed>, version: int}
	 * @throws DomainError When its WooCommerce product is gone.
	 */
	private function full( array $bundle ): array {
		$content = $this->woo->read( $bundle['product']->wooProductId ) ?? throw self::unknown();

		return array(
			'product' => ProductSerializer::full( $bundle['product'], $bundle['offer'], $bundle['variations'], $bundle['balances'], $content, $this->clock->now() ),
			'version' => $bundle['product']->version,
		);
	}

	/**
	 * Checks an action against `CatalogPolicy`.
	 *
	 * @param Product $product Product.
	 * @param string  $action  Action.
	 * @throws DomainError When it's refused: not found, or forbidden within the actor's own store.
	 */
	private function allow( Product $product, string $action ): void {
		$decision = $this->policy->decide( $this->actors->identity(), $action, $product );
		if ( AccessDecision::Forbidden === $decision ) {
			throw DomainError::of( 'mercatus_product_moderation_not_allowed' );
		}
		if ( AccessDecision::Allowed !== $decision ) {
			throw self::unknown();
		}
	}

	/**
	 * Cleans text before it's checked: descriptions keep the rich-text allow-list (CTL-2, TM-28); everything
	 * else a vendor names is plain text.
	 *
	 * @param array<string, mixed> $input Fields.
	 * @return array<string, mixed>
	 */
	private function clean( array $input ): array {
		foreach ( $input as $field => $value ) {
			$input[ $field ] = match ( $field ) {
				'description', 'short_description' => is_string( $value ) ? RichText::clean( $value ) : $value,
				'name', 'sku'                      => self::plain( $value ),
				'tags'                             => is_array( $value ) ? array_map( array( self::class, 'plain' ), $value ) : $value,
				'attributes'                       => is_array( $value ) ? array_map( array( self::class, 'cleanAttribute' ), $value ) : $value,
				'variations'                       => is_array( $value ) ? array_map( array( self::class, 'cleanVariation' ), $value ) : $value,
				default                            => $value,
			};
		}

		return $input;
	}

	/**
	 * A vendor's attribute, as plain text.
	 *
	 * @param mixed $attribute Attribute.
	 */
	private static function cleanAttribute( mixed $attribute ): mixed {
		if ( ! is_array( $attribute ) ) {
			return $attribute;
		}
		if ( array_key_exists( 'name', $attribute ) ) {
			$attribute['name'] = self::plain( $attribute['name'] );
		}
		if ( is_array( $attribute['values'] ?? null ) ) {
			$attribute['values'] = array_map( array( self::class, 'plain' ), $attribute['values'] );
		}

		return $attribute;
	}

	/**
	 * A vendor's variation, its text cleaned.
	 *
	 * @param mixed $variation Variation.
	 */
	private static function cleanVariation( mixed $variation ): mixed {
		if ( ! is_array( $variation ) ) {
			return $variation;
		}
		if ( is_string( $variation['description'] ?? null ) ) {
			$variation['description'] = RichText::clean( $variation['description'] );
		}
		if ( array_key_exists( 'sku', $variation ) ) {
			$variation['sku'] = self::plain( $variation['sku'] );
		}
		if ( is_array( $variation['attributes'] ?? null ) ) {
			$chosen = array();
			foreach ( $variation['attributes'] as $name => $value ) {
				$chosen[ sanitize_text_field( (string) $name ) ] = self::plain( $value );
			}
			$variation['attributes'] = $chosen;
		}

		return $variation;
	}

	/**
	 * Plain text, when it's text.
	 *
	 * @param mixed $value Value.
	 */
	private static function plain( mixed $value ): mixed {
		return is_string( $value ) ? sanitize_text_field( $value ) : $value;
	}

	/**
	 * A moderator's reason: plain text, required and within its length.
	 *
	 * @param string $reason Reason.
	 * @throws DomainError When it's empty or too long.
	 */
	private static function reason( string $reason ): string {
		$reason = trim( sanitize_textarea_field( $reason ) );
		if ( '' === $reason || mb_strlen( $reason ) > CatalogFields::REASON_MAX ) {
			throw self::invalid( 'reason' );
		}

		return $reason;
	}

	/**
	 * Checks that the named categories exist.
	 *
	 * @param array<string, mixed> $valid Valid fields.
	 * @throws DomainError When one doesn't.
	 */
	private function assertCategories( array $valid ): void {
		if ( isset( $valid['categories'] ) && is_array( $valid['categories'] ) && ! $this->woo->categoriesExist( $valid['categories'] ) ) {
			throw self::invalid( 'categories' );
		}
	}

	/**
	 * Checks that the SKUs a change names are free for the seller (CTL-26): another of the seller's products
	 * doesn't use them, and the change doesn't name one twice.
	 *
	 * @param int                        $seller     Seller.
	 * @param int                        $product_id Catalog product being changed, or 0.
	 * @param string                     $sku        The product's SKU, or ''.
	 * @param list<array<string, mixed>> $variations Valid variations.
	 * @throws DomainError When one is taken (mercatus_product_sku_taken).
	 */
	private function assertSkus( int $seller, int $product_id, string $sku, array $variations ): void {
		$skus = array_values( array_filter( array_merge( array( $sku ), array_map( static fn( array $variation ): string => (string) ( $variation['sku'] ?? '' ), $variations ) ), static fn( string $one ): bool => '' !== $one ) );
		if ( count( $skus ) !== count( array_unique( array_map( 'mb_strtolower', $skus ) ) ) ) {
			throw DomainError::of( 'mercatus_product_sku_taken', array( 'field' => 'sku' ) );
		}
		foreach ( $skus as $one ) {
			if ( array() !== array_diff( $this->availability->productsWithSku( $seller, $one ), array( $product_id ) ) ) {
				throw DomainError::of( 'mercatus_product_sku_taken', array( 'field' => 'sku' ) );
			}
		}
	}

	/**
	 * Checks that a product can be published: a simple product needs a regular price, a variable one an
	 * enabled variation with one (CTL-22).
	 *
	 * @param Product              $product    Product.
	 * @param Offer                $offer      Offer.
	 * @param list<OfferVariation> $variations Variation terms.
	 * @throws DomainError When it can't (mercatus_product_incomplete).
	 */
	private static function assertComplete( Product $product, Offer $offer, array $variations ): void {
		if ( Product::SIMPLE === $product->type && null === $offer->prices->regular ) {
			throw DomainError::of( 'mercatus_product_incomplete', array( 'field' => 'regular_price' ) );
		}
		if ( Product::VARIABLE === $product->type && array() === array_filter( $variations, static fn( OfferVariation $variation ): bool => $variation->isEnabled() && null !== $variation->prices->regular ) ) {
			throw DomainError::of( 'mercatus_product_incomplete', array( 'field' => 'variations' ) );
		}
	}

	/**
	 * A balance change from an inventory item: a quantity to set or a delta to apply, and stock settings.
	 *
	 * @param mixed               $item     Item: variation_id, quantity or delta, manage_stock, stock_status, backorders.
	 * @param array<int, Balance> $balances The offer's balances by variation.
	 * @return array{0: int, 1: Balance, 2: string} Variation, its new balance (at the old version), and why.
	 * @throws DomainError When it doesn't fit (mercatus_inventory_invalid).
	 */
	private static function adjusted( mixed $item, array $balances ): array {
		$fields = array( 'variation_id', 'quantity', 'delta', 'manage_stock', 'stock_status', 'backorders' );
		if ( ! is_array( $item ) || array() !== array_diff( array_map( 'strval', array_keys( $item ) ), $fields ) || ( isset( $item['quantity'] ) && isset( $item['delta'] ) ) ) {
			throw DomainError::of( 'mercatus_inventory_invalid', array( 'field' => 'items' ) );
		}
		$variation_id = $item['variation_id'] ?? 0;
		if ( ! is_int( $variation_id ) || ! isset( $balances[ $variation_id ] ) ) {
			throw DomainError::of( 'mercatus_inventory_invalid', array( 'field' => 'variation_id' ) );
		}
		$old = $balances[ $variation_id ];
		try {
			$quantity = match ( true ) {
				isset( $item['quantity'] ) => ProductInput::quantity( 'quantity', $item['quantity'] ),
				isset( $item['delta'] )    => $old->quantity->add( ProductInput::quantity( 'delta', $item['delta'] ) ),
				default                    => $old->quantity,
			};
		} catch ( DomainError $error ) {
			throw DomainError::of( 'mercatus_inventory_invalid', array( 'field' => (string) ( $error->details['field'] ?? 'quantity' ) ) );
		}
		$manage     = $item['manage_stock'] ?? $old->manageStock;
		$backorders = $item['backorders'] ?? $old->backorders;
		$chosen     = $item['stock_status'] ?? $old->stockStatus;
		if ( ! is_bool( $manage ) || ! in_array( $backorders, Balance::BACKORDERS, true ) || ! in_array( $chosen, Balance::STOCK_STATUSES, true ) || ( $quantity->isNegative() && 'no' === $backorders ) ) {
			throw DomainError::of( 'mercatus_inventory_invalid', array( 'field' => 'items' ) );
		}

		return array(
			$variation_id,
			new Balance( $old->id, $old->offerId, $variation_id, $old->locationId, $manage, $quantity, Balance::status( $manage, $quantity, $backorders, $chosen ), $backorders, $old->version ),
			isset( $item['delta'] ) ? 'adjust' : 'set',
		);
	}

	/**
	 * Records a new balance with its first movement (CTL-6).
	 *
	 * @param Balance           $balance New balance.
	 * @param DateTimeImmutable $now     Current time.
	 */
	private function openBalance( Balance $balance, DateTimeImmutable $now ): void {
		$id = $this->inventory->insert( $balance, $now );
		$this->inventory->move( Uuid::v7( $this->clock ), $id, $balance->quantity, $balance->quantity, 'initial', $this->actors->identity()->ref(), $now );
	}

	/**
	 * Publishes the delayed events that rebuild a product when a scheduled sale starts or ends (CTL-22).
	 *
	 * @param int               $woo_id     WooCommerce product.
	 * @param int               $product_id Catalog product.
	 * @param list<Prices>      $prices     The product's prices: its own and its variations'.
	 * @param DateTimeImmutable $now        Current time.
	 */
	private function scheduleSales( int $woo_id, int $product_id, array $prices, DateTimeImmutable $now ): void {
		$times = array();
		foreach ( $prices as $set ) {
			foreach ( $set->upcomingChanges( $now ) as $at ) {
				$times[ $at->getTimestamp() ] = $at;
			}
		}
		foreach ( $times as $at ) {
			$this->events->publish( 'catalog.offer.price_changed', 'product', $woo_id, 0, array( 'product_id' => $woo_id ), 1, EventLane::Bulk, 'offer_proj:' . $product_id, $at );
		}
	}

	/**
	 * Records the terms a write created (CTL-21).
	 *
	 * @param list<array{taxonomy: string, term_id: int}> $terms     Created terms.
	 * @param int                                         $woo_id    WooCommerce product.
	 * @param int                                         $vendor_id Vendor.
	 */
	private function termsCreated( array $terms, int $woo_id, int $vendor_id ): void {
		foreach ( $terms as $term ) {
			$this->events->publish(
				'catalog.term.created',
				'term',
				$term['term_id'],
				1,
				array(
					'taxonomy'   => $term['taxonomy'],
					'term_id'    => $term['term_id'],
					'vendor_id'  => $vendor_id,
					'product_id' => $woo_id,
				)
			);
			$this->audit->record( 'term.created', 'term', $term['term_id'], array( 'taxonomy' => $term['taxonomy'] ) );
		}
	}

	/**
	 * The current vendor.
	 *
	 * @throws DomainError When the actor isn't one.
	 */
	private function vendorId(): int {
		return $this->actors->identity()->vendorId ?? throw DomainError::of( 'mercatus_store_unknown' );
	}

	/**
	 * A seller's default location (CAT-1, STO-4).
	 *
	 * @param int $vendor_id Seller.
	 * @throws DomainError When the seller has no store yet.
	 */
	private function location( int $vendor_id ): int {
		return $this->stores->ofVendor( $vendor_id )[1]->id ?? throw DomainError::of( 'mercatus_store_unknown' );
	}

	/**
	 * Whether a seller's store is open; the marketplace always is (CTL-13).
	 *
	 * @param int $vendor_id Seller.
	 */
	private function storeOpen( int $vendor_id ): bool {
		$pair = Product::MARKETPLACE === $vendor_id ? null : $this->stores->ofVendor( $vendor_id );

		return Product::MARKETPLACE === $vendor_id || ( null !== $pair && $pair[0]->isListed() );
	}

	/**
	 * Drops a product's cached copy and its projection pages.
	 *
	 * @param int $product_id Catalog product.
	 */
	private function changed( int $product_id ): void {
		$this->cache->bumpRevision( AvailabilityProjector::productScope( $product_id ) );
	}

	/**
	 * New prices: the current ones with a change's fields applied.
	 *
	 * @param Prices               $current Current prices.
	 * @param array<string, mixed> $changes Valid price fields: regular_price, sale_price, sale_starts_at, sale_ends_at.
	 * @throws DomainError When they don't fit together.
	 */
	private static function merge( Prices $current, array $changes ): Prices {
		$pick    = static fn( string $field, mixed $now ): mixed => array_key_exists( $field, $changes ) ? $changes[ $field ] : $now;
		$regular = $pick( 'regular_price', $current->regular );
		$sale    = $pick( 'sale_price', $current->sale );
		$starts  = $pick( 'sale_starts_at', $current->saleStartsAt );
		$ends    = $pick( 'sale_ends_at', $current->saleEndsAt );

		return new Prices(
			$regular instanceof Decimal ? $regular : null,
			$sale instanceof Decimal ? $sale : null,
			$sale instanceof Decimal && $starts instanceof DateTimeImmutable ? $starts : null,
			$sale instanceof Decimal && $ends instanceof DateTimeImmutable ? $ends : null,
		);
	}

	/**
	 * A new balance's values, before it has an offer.
	 *
	 * @param int                                                                         $location_id Location.
	 * @param array{manage_stock: bool, quantity: Decimal, stock_status: string, backorders: string} $stock Valid stock.
	 */
	private static function balance( int $location_id, array $stock ): Balance {
		return new Balance( 0, 0, 0, $location_id, $stock['manage_stock'], $stock['quantity'], Balance::status( $stock['manage_stock'], $stock['quantity'], $stock['backorders'], $stock['stock_status'] ), $stock['backorders'], 1 );
	}

	/**
	 * A new balance placed on its offer and variation.
	 *
	 * @param Balance $balance      New balance's values.
	 * @param int     $offer_id     Offer.
	 * @param int     $variation_id Variation, or 0.
	 */
	private static function placed( Balance $balance, int $offer_id, int $variation_id ): Balance {
		return new Balance( 0, $offer_id, $variation_id, $balance->locationId, $balance->manageStock, $balance->quantity, $balance->stockStatus, $balance->backorders, 1 );
	}

	/**
	 * The WooCommerce content among valid fields.
	 *
	 * @param array<string, mixed> $valid Valid fields.
	 * @return array<string, mixed>
	 */
	private static function content( array $valid ): array {
		return array_intersect_key( $valid, array_flip( array( 'name', 'description', 'short_description', 'sku', 'virtual', 'weight', 'length', 'width', 'height', 'categories', 'tags', 'attributes' ) ) );
	}

	/**
	 * A variation's WooCommerce content.
	 *
	 * @param array<string, mixed> $variation Valid variation.
	 * @return array<string, mixed>
	 */
	private static function variationContent( array $variation ): array {
		return array_diff_key( $variation, array_flip( array( 'id', 'prices', 'stock' ) ) );
	}

	/**
	 * A variation's state: as the change says, else as it was, else enabled.
	 *
	 * @param array<string, mixed> $content Its content.
	 * @param OfferVariation|null  $current Its current terms.
	 */
	private static function variationStatus( array $content, ?OfferVariation $current ): string {
		if ( isset( $content['enabled'] ) ) {
			return true === $content['enabled'] ? OfferVariation::ENABLED : OfferVariation::DISABLED;
		}

		return null === $current ? OfferVariation::ENABLED : $current->status;
	}

	/**
	 * The primary category: the first a change names, else the current one.
	 *
	 * @param array<string, mixed> $valid   Valid fields.
	 * @param int|null             $current Current primary category.
	 */
	private static function primaryCategory( array $valid, ?int $current ): ?int {
		if ( ! isset( $valid['categories'] ) || ! is_array( $valid['categories'] ) ) {
			return $current;
		}
		$first = $valid['categories'][0] ?? null;

		return is_int( $first ) ? $first : null;
	}

	/**
	 * The names of the fields a change set, prices by their own names.
	 *
	 * @param array<string, mixed> $valid Valid fields.
	 * @return list<string>
	 */
	private static function changedFields( array $valid ): array {
		$fields = array_keys( array_diff_key( $valid, array( 'prices' => true ) ) );
		if ( isset( $valid['prices'] ) && is_array( $valid['prices'] ) ) {
			$fields = array_merge( $fields, array_keys( $valid['prices'] ) );
		}

		return array_map( 'strval', $fields );
	}

	/**
	 * The error for a product the actor may not see.
	 */
	private static function unknown(): DomainError {
		return DomainError::of( 'mercatus_product_unknown' );
	}

	/**
	 * The error for a change based on an old version.
	 *
	 * @param Product $product Product.
	 */
	private static function conflict( Product $product ): DomainError {
		return DomainError::of( 'mercatus_version_conflict', array( 'product_id' => $product->wooProductId ) );
	}

	/**
	 * The error for a field that doesn't fit.
	 *
	 * @param string $field Field.
	 */
	private static function invalid( string $field ): DomainError {
		return DomainError::of( 'mercatus_product_invalid', array( 'field' => $field ) );
	}

	/**
	 * The error for an action the product's state doesn't allow.
	 *
	 * @param Product $product Product.
	 * @param string  $action  Action.
	 */
	private static function notAllowed( Product $product, string $action ): DomainError {
		return DomainError::of(
			'mercatus_transition_not_allowed',
			array(
				'lifecycle' => 'catalog_product',
				'from'      => $product->status,
				'action'    => $action,
			)
		);
	}
}
