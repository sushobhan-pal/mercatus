<?php
/**
 * Every store gets one default location (CAT-1, STO-4).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Integration\Store;

use WP_UnitTestCase;

/**
 * The location is created with the store, active, default and with an empty address; changing the address
 * changes that one location and clears coordinates that belonged to the old address.
 */
final class DefaultLocationAutoCreationTest extends WP_UnitTestCase {

	use StoreTestHelpers;

	public function testApprovalCreatesOneActiveDefaultLocation(): void {
		$vendor = $this->vendorWithStore();

		$rows = $this->locations( $vendor['vendor_id'] );
		$this->assertCount( 1, $rows );
		$this->assertSame( array( 'default', '1', 'active', '' ), array( $rows[0]['type'], $rows[0]['is_default'], $rows[0]['status'], $rows[0]['city'] ) );
		$this->assertNull( $rows[0]['geo_cell'] );
	}

	public function testTheAddressBelongsToTheDefaultLocation(): void {
		global $wpdb;
		$vendor = $this->vendorWithStore();
		$wpdb->update(
			$wpdb->prefix . 'mercatus_fulfilment_locations',
			array(
				'latitude'  => 51.5,
				'longitude' => -0.12,
				'geo_cell'  => 'gcpvj0',
			),
			array( 'vendor_id' => $vendor['vendor_id'] )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- fixture: coordinates a geocoder would have set.

		$store = $this->data(
			$this->call(
				'PATCH',
				'/me/store',
				array(
					'address' => array(
						'city'    => 'Leeds',
						'country' => 'gb',
					),
				),
				$this->etag( $this->call( 'GET', '/me/store' ) )
			),
			200
		);

		$rows = $this->locations( $vendor['vendor_id'] );
		$this->assertCount( 1, $rows, 'Still one location.' );
		$this->assertSame( array( 'Leeds', 'GB' ), array( $rows[0]['city'], $rows[0]['country'] ) );
		$this->assertNull( $rows[0]['geo_cell'], 'The old coordinates went with the old address.' );
		$this->assertSame( 'Leeds', ( (array) $store['address'] )['city'] );
		$this->assertNull( $store['coordinates'] );
	}

	/**
	 * A vendor's locations as stored.
	 *
	 * @param int $vendor_id Vendor.
	 * @return list<array<string, string|null>>
	 */
	private function locations( int $vendor_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT type, is_default, status, city, country, geo_cell FROM %i WHERE vendor_id = %d', $wpdb->prefix . 'mercatus_fulfilment_locations', $vendor_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test assertion.

		return is_array( $rows ) ? array_values( $rows ) : array();
	}
}
