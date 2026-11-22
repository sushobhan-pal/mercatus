<?php
/**
 * A scheduled sale changes the price by itself (CTL-22, API-3).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Integration\Catalog;

use DateTimeImmutable;
use DateTimeZone;
use Mercatus\Core\Catalog\Infrastructure\Products;
use WP_UnitTestCase;

/**
 * Saving prices with a future start or end queues a bulk-lane event for each, available only then; when it
 * arrives, WooCommerce's price and the projection follow.
 */
final class ScheduledSaleProjectionTest extends WP_UnitTestCase {

	use CatalogTestHelpers;

	public function testAScheduledSaleQueuesTheChangesItWillMake(): void {
		global $wpdb;
		$this->vendorWithStore();
		$starts = self::utc( '+1 day' );
		$ends   = self::utc( '+3 days' );
		$id     = (int) $this->createProduct(
			array(
				'sale_price'     => '15',
				'sale_starts_at' => $starts->format( DATE_ATOM ),
				'sale_ends_at'   => $ends->format( DATE_ATOM ),
			)
		)['id'];

		$this->assertSame( '20.000000', self::productRow( $id )['effective_price'], 'Not yet.' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- reading the outbox the command wrote.
		$queued = $wpdb->get_results( $wpdb->prepare( 'SELECT lane, coalesce_key, available_at FROM %i WHERE event_name = %s AND aggregate_id = %s ORDER BY available_at', $wpdb->prefix . 'mercatus_events_outbox', 'catalog.offer.price_changed', (string) $id ), ARRAY_A );
		$this->assertSame(
			array(
				array( 'bulk', 'offer_proj:' . self::catalogProduct( $id )->id, $starts->format( 'Y-m-d H:i:s' ) ),
				array( 'bulk', 'offer_proj:' . self::catalogProduct( $id )->id, $ends->format( 'Y-m-d H:i:s' ) ),
			),
			array_map( static fn( array $row ): array => array( $row['lane'], $row['coalesce_key'], substr( (string) $row['available_at'], 0, 19 ) ), (array) $queued )
		);
	}

	public function testWhenASaleEndsThePriceAndTheRowsFollow(): void {
		global $wpdb;
		$this->vendorWithStore();
		$product = $this->createProduct(
			array(
				'sale_price'     => '15',
				'sale_starts_at' => self::utc( '-1 day' )->format( DATE_ATOM ),
				'sale_ends_at'   => self::utc( '+1 day' )->format( DATE_ATOM ),
			)
		);
		$id      = (int) $product['id'];
		$this->assertSame( array( '15', '15', '15.000000' ), array( $product['effective_price'], self::woo( $id )->get_price(), self::productRow( $id )['effective_price'] ) );

		// The sale's end passes.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- moving the sale into the past.
		$wpdb->update( $wpdb->prefix . 'mercatus_vendor_offers', array( 'sale_ends_at' => self::utc( '-1 minute' )->format( 'Y-m-d H:i:s.v' ) ), array( 'id' => (int) $product['offer']['id'] ) );
		self::kernel()->container()->get( Products::class )->refreshPrices( $id );

		$this->assertSame( array( '20', '20.000000' ), array( self::woo( $id )->get_price(), self::productRow( $id )['effective_price'] ) );
	}

	/**
	 * A UTC time, to the second.
	 *
	 * @param string $relative Relative time.
	 */
	private static function utc( string $relative ): DateTimeImmutable {
		return new DateTimeImmutable( '@' . ( new DateTimeImmutable( $relative, new DateTimeZone( 'UTC' ) ) )->getTimestamp() );
	}
}
