<?php
/**
 * The availability projection's rows for a product (CAT-5, CTL-9, CTL-13).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Catalog\Infrastructure;

use Mercatus\Core\Catalog\Domain\Balance;
use Mercatus\Core\Catalog\Domain\EffectivePriceResolver;
use Mercatus\Core\Catalog\Domain\Offer;
use Mercatus\Core\Catalog\Domain\Product;
use Mercatus\Core\Catalog\Domain\ProductLifecycle;
use Mercatus\Core\Catalog\Domain\WooStatus;
use Mercatus\Core\Shared\Clock\Clock;
use Mercatus\Core\Shared\Money\Decimal;
use Mercatus\Core\Shared\Projection\Projector;
use Mercatus\Core\Store\Domain\Location;
use Mercatus\Core\Store\Infrastructure\Stores;
use Mercatus\Core\Store\Infrastructure\StoreUrls;

/**
 * Keyed by catalog product. Each offer has a row for the product as a whole at each of its locations, and a
 * variable product a row per variation too. A row is purchasable only while buyers may see the product
 * (approved, offer active, store open), it has a price and stock allows it. The card holds what a list
 * renders, so lists never touch WooCommerce (ARCH-9).
 */
final class AvailabilityProjector implements Projector {

	/**
	 * Creates the projector.
	 *
	 * @param ProductRepository      $products     Catalog products.
	 * @param OfferRepository        $offers       Offers.
	 * @param InventoryRepository    $inventory    Stock.
	 * @param AvailabilityRepository $availability The projection's table.
	 * @param WooCatalog             $woo          WooCommerce content.
	 * @param Stores                 $stores       Stores, for openness, location and the card.
	 * @param StoreUrls              $urls         Store page addresses.
	 * @param EffectivePriceResolver $resolver     Effective prices.
	 * @param Clock                  $clock        Clock.
	 */
	public function __construct(
		private ProductRepository $products,
		private OfferRepository $offers,
		private InventoryRepository $inventory,
		private AvailabilityRepository $availability,
		private WooCatalog $woo,
		private Stores $stores,
		private StoreUrls $urls,
		private EffectivePriceResolver $resolver,
		private Clock $clock,
	) {}

	public function desired( int $key ): array {
		$product = $this->products->byId( $key );
		$content = null === $product || Product::DELETED === $product->status ? null : $this->woo->read( $product->wooProductId );
		if ( null === $product || null === $content ) {
			return array();
		}
		$now  = $this->clock->now();
		$rows = array();
		foreach ( $this->offers->forProduct( $product->id ) as $offer ) {
			$pair     = Product::MARKETPLACE === $offer->vendorId ? null : $this->stores->ofVendor( $offer->vendorId );
			$open     = Product::MARKETPLACE === $offer->vendorId || ( null !== $pair && $pair[0]->isListed() );
			$visible  = WooStatus::visible( $product->status, $offer->status, $open );
			$state    = ProductLifecycle::visible( $product->status, $offer->status );
			$balances = $this->inventory->forOffer( $offer->id );
			$card     = array(
				'id'    => $product->wooProductId,
				'type'  => $product->type,
				'name'  => (string) $content['name'],
				'url'   => (string) $content['url'],
				'store' => null === $pair ? null : array(
					'name' => $pair[0]->name,
					'url'  => $this->urls->url( $pair[0]->slug ),
				),
			);
			$location = $pair[1] ?? null;
			foreach ( $this->offers->locations( $offer->id ) as $location_id ) {
				if ( Product::SIMPLE === $product->type ) {
					$balance  = $balances[0] ?? null;
					$price    = $this->resolver->resolve( $offer->prices, $now, $location_id );
					$sellable = null !== $price && null !== $balance && $balance->sellable();
					$rows[ AvailabilityRepository::key( $offer->id, 0, $location_id ) ] = $this->row( $product, $offer, 0, $location_id, $state, $visible && $sellable, array( $price, $price, $price ), $balance, (string) $content['name'], (string) $content['sku'], $card, $location );
					continue;
				}
				$names = array();
				foreach ( is_array( $content['variations'] ?? null ) ? $content['variations'] : array() as $item ) {
					$names[ (int) $item['id'] ] = $item;
				}
				$enabled  = array();
				$sellable = false;
				foreach ( $this->offers->variations( $offer->id ) as $variation ) {
					$item = $names[ $variation->variationId ] ?? null;
					if ( null === $item ) {
						continue;
					}
					$balance = $balances[ $variation->variationId ] ?? null;
					$price   = $this->resolver->resolve( $variation->prices, $now, $location_id );
					$sells   = $variation->isEnabled() && null !== $price && null !== $balance && $balance->sellable();
					$label   = trim( (string) $content['name'] . ' – ' . implode( ', ', array_map( 'strval', (array) $item['attributes'] ) ) );
					$rows[ AvailabilityRepository::key( $offer->id, $variation->variationId, $location_id ) ] = $this->row(
						$product,
						$offer,
						$variation->variationId,
						$location_id,
						$variation->isEnabled() ? $state : 'disabled',
						$visible && $sells,
						array( $price, $price, $price ),
						$balance,
						$label,
						(string) $item['sku'],
						$card + array(
							'variation'  => $variation->variationId,
							'attributes' => $item['attributes'],
						),
						$location
					);
					if ( $variation->isEnabled() ) {
						$enabled[] = $variation->prices;
					}
					$sellable = $sellable || $sells;
				}
				list( $low, $high ) = $this->resolver->range( $enabled, $now, $location_id );
				$rows[ AvailabilityRepository::key( $offer->id, 0, $location_id ) ] = $this->row( $product, $offer, 0, $location_id, $state, $visible && $sellable, array( $low, $low, $high ), null, (string) $content['name'], (string) $content['sku'], $card, $location, $sellable );
			}
		}

		return $rows;
	}

	public function stored( int $key ): array {
		return $this->availability->rows( $key );
	}

	public function write( int $key, array $upserts, array $deletes ): void {
		$this->availability->write( $key, $upserts, $deletes );
	}

	public function scope( int $key ): string {
		return self::productScope( $key );
	}

	/**
	 * A product's revision scope.
	 *
	 * @param int $product_id Catalog product.
	 */
	public static function productScope( int $product_id ): string {
		return 'catalog-product:' . $product_id;
	}

	/**
	 * One row in its stored form.
	 *
	 * @param Product                                          $product      Catalog product.
	 * @param Offer                                            $offer        Offer.
	 * @param int                                              $variation_id Variation, or 0.
	 * @param int                                              $location_id  Location.
	 * @param string                                           $state        The state the vendor sees.
	 * @param bool                                             $purchasable  Whether buyers may buy it.
	 * @param array{0: Decimal|null, 1: Decimal|null, 2: Decimal|null} $prices Effective, lowest and highest price.
	 * @param Balance|null                                     $balance      Stock, for a sellable unit.
	 * @param string                                           $name         Name.
	 * @param string                                           $sku          SKU.
	 * @param array<string, mixed>                             $card         What a list renders.
	 * @param Location|null                                    $location     The location, for discovery.
	 * @param bool|null                                        $in_stock     For a variable product's row: whether any variation sells.
	 * @return array<string, string|null>
	 */
	private function row( Product $product, Offer $offer, int $variation_id, int $location_id, string $state, bool $purchasable, array $prices, ?Balance $balance, string $name, string $sku, array $card, ?Location $location, ?bool $in_stock = null ): array {
		$status = null === $balance ? ( true === $in_stock ? Balance::IN_STOCK : Balance::OUT_OF_STOCK ) : $balance->stockStatus;

		return array(
			'vendor_offer_id'    => (string) $offer->id,
			'variation_id'       => (string) $variation_id,
			'location_id'        => (string) $location_id,
			'catalog_product_id' => (string) $product->id,
			'vendor_id'          => (string) $offer->vendorId,
			'status'             => $state,
			'is_purchasable'     => $purchasable ? '1' : '0',
			'effective_price'    => $prices[0]?->toDatabase(),
			'min_price'          => $prices[1]?->toDatabase(),
			'max_price'          => $prices[2]?->toDatabase(),
			'stock_status'       => $status,
			'stock_quantity'     => null !== $balance && $balance->manageStock ? $balance->quantity->toDatabase() : null,
			'name'               => mb_substr( $name, 0, 191 ),
			'sku'                => mb_substr( $sku, 0, 100 ),
			'card'               => (string) wp_json_encode( $card ),
			'geo_cell'           => $location?->geoCell,
			'latitude'           => null === $location?->latitude ? null : sprintf( '%.7F', $location->latitude ),
			'longitude'          => null === $location?->longitude ? null : sprintf( '%.7F', $location->longitude ),
			'updated_at'         => $product->updatedAt,
			'version'            => (string) $product->version,
		);
	}
}
