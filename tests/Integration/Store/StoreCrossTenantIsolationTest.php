<?php
/**
 * One vendor never reaches another's store (TM-3, TM-4, SEC-3, SEC-4).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Integration\Store;

use WP_UnitTestCase;

/**
 * /me/store is always the caller's own; another vendor's hidden store is not found, by slug or ID and with
 * context=edit; the administrators' PATCH /stores/{store} refuses vendors; and a change can't move a store to
 * another vendor or set its status.
 */
final class StoreCrossTenantIsolationTest extends WP_UnitTestCase {

	use StoreTestHelpers;

	public function testAVendorReachesOnlyTheirOwnStore(): void {
		$other = $this->vendorWithStore( 'Other Shop' );
		$mine  = $this->vendorWithStore( 'My Shop' );

		$this->assertSame( $mine['store_id'], $this->data( $this->call( 'GET', '/me/store' ), 200 )['id'] );
		$this->assertSame( 'mercatus_store_unknown', $this->data( $this->call( 'GET', '/stores/' . $other['slug'] ), 404 )['code'], 'Another vendor\'s hidden store is not found, not forbidden.' );
		$this->assertSame( 'mercatus_store_unknown', $this->data( $this->call( 'GET', '/stores/' . $other['store_id'] ), 404 )['code'], 'By ID too.' );
		$this->assertSame( 'mercatus_store_unknown', $this->data( $this->call( 'GET', '/stores/' . $other['store_id'], array( 'context' => 'edit' ) ), 404 )['code'] );
		$this->data( $this->call( 'PATCH', '/stores/' . $other['store_id'], array( 'name' => 'Mine now' ), '"v1"' ), 403 );
	}

	public function testAChangeNeverMovesAStoreOrSetsItsStatus(): void {
		$other = $this->vendorWithStore( 'Other Shop' );
		$this->vendorWithStore( 'My Shop' );

		$store = $this->data(
			$this->call(
				'PATCH',
				'/me/store',
				array(
					'tagline'   => 'Hand-drawn maps',
					'vendor_id' => $other['vendor_id'],
					'status'    => 'suspended',
					'id'        => $other['store_id'],
				),
				$this->etag( $this->call( 'GET', '/me/store' ) )
			),
			200
		);

		$this->assertSame( array( 'Hand-drawn maps', 'active' ), array( $store['tagline'], $store['status'] ) );
		$this->assertNotSame( $other['vendor_id'], $store['vendor_id'] );
		$this->assertSame( 'Other Shop', $this->data( $this->asAdministrator( 'GET', '/stores/' . $other['store_id'], array( 'context' => 'edit' ) ), 200 )['name'] );
	}

	public function testOnlyAdministratorsListHiddenStores(): void {
		$this->vendorWithStore();

		$this->assertSame( 'mercatus_store_filter_not_allowed', $this->data( $this->call( 'GET', '/stores', array( 'visibility' => 'hidden' ) ), 403 )['code'] );
		$this->assertCount( 1, $this->data( $this->asAdministrator( 'GET', '/stores', array( 'visibility' => 'hidden' ) ), 200 )['items'] );
	}
}
