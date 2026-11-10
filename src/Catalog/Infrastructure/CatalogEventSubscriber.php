<?php
/**
 * core.catalog's bulk-lane work (API-3, CTL-22).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Catalog\Infrastructure;

use Mercatus\Core\Contracts\Events\EventSubscriber;
use Mercatus\Core\Shared\Events\DomainEvent;

/**
 * When a scheduled sale starts or ends, the delayed event published with the prices arrives, and the product's
 * WooCommerce price and projection rows follow.
 */
final class CatalogEventSubscriber implements EventSubscriber {

	/**
	 * Creates the subscriber.
	 *
	 * @param Products $products Products.
	 */
	public function __construct( private Products $products ) {}

	public function subscribedEvents(): array {
		return array( 'catalog.offer.price_changed' );
	}

	public function handle( DomainEvent $event ): void {
		$product_id = $event->payload['product_id'] ?? null;
		if ( is_int( $product_id ) ) {
			$this->products->refreshPrices( $product_id );
		}
	}
}
