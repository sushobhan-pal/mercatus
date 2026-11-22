<?php
/**
 * Sends the vendor's moderation emails (CTL-20).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Catalog\Infrastructure\Email;

use Mercatus\Core\Catalog\Infrastructure\ProductRepository;
use Mercatus\Core\Catalog\Infrastructure\WooCatalog;
use Mercatus\Core\Contracts\Events\EventSubscriber;
use Mercatus\Core\Shared\Events\DomainEvent;
use Mercatus\Core\Store\Infrastructure\Stores;
use Mercatus\Core\Vendor\Infrastructure\DashboardPage;
use Mercatus\Core\Vendor\Infrastructure\VendorStore;

/**
 * The event carries IDs only (SEC-7); the reason is read from the product, and the email links to it in the
 * dashboard.
 */
final class CatalogEmailSubscriber implements EventSubscriber {

	private const EMAILS = array(
		'catalog.product.approved'   => ProductApprovedEmail::class,
		'catalog.product.rejected'   => ProductRejectedEmail::class,
		'catalog.product.taken_down' => ProductTakenDownEmail::class,
	);

	/**
	 * Creates the subscriber.
	 *
	 * @param ProductRepository $products  Catalog products, for the reason.
	 * @param WooCatalog        $woo       WooCommerce, for the product's name.
	 * @param VendorStore       $vendors   Vendors, for their user.
	 * @param Stores            $stores    Stores, for the store's name.
	 * @param DashboardPage     $dashboard The dashboard, for the link.
	 */
	public function __construct( private ProductRepository $products, private WooCatalog $woo, private VendorStore $vendors, private Stores $stores, private DashboardPage $dashboard ) {}

	public function subscribedEvents(): array {
		return array_keys( self::EMAILS );
	}

	public function handle( DomainEvent $event ): void {
		$class      = self::EMAILS[ $event->name ] ?? null;
		$email      = null === $class ? null : ( WC()->mailer()->get_emails()[ $class ] ?? null );
		$product_id = $event->payload['product_id'] ?? null;
		$vendor_id  = $event->payload['vendor_id'] ?? null;
		$vendor     = is_int( $vendor_id ) ? $this->vendors->byId( $vendor_id ) : null;
		if ( ! $email instanceof ProductEmail || ! is_int( $product_id ) || null === $vendor ) {
			return;
		}
		$product = $this->products->byWooId( $product_id );
		$content = $this->woo->read( $product_id );
		$email->trigger(
			$vendor->userId,
			array(
				'store_name'    => $this->stores->ofVendor( $vendor->id )[0]->name ?? '',
				'product_name'  => (string) ( $content['name'] ?? '' ),
				'reason'        => $product?->statusReason,
				'dashboard_url' => $this->dashboard->url() . '#/products/' . $product_id,
			)
		);
	}
}
