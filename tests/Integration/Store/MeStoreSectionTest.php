<?php
/**
 * The store summary in /me (IDN-12, STO-13).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Integration\Store;

use WP_UnitTestCase;

/**
 * A vendor's /me names their store and whether it's listed, so the dashboard can prompt them to open it; an
 * actor without a store gets no section.
 */
final class MeStoreSectionTest extends WP_UnitTestCase {

	use StoreTestHelpers;

	public function testAVendorSeesTheirStoreAndWhetherItIsListed(): void {
		$vendor = $this->vendorWithStore();

		$section = (array) $this->data( $this->call( 'GET', '/me' ), 200 )['sections'];
		$this->assertSame(
			array(
				'id'         => $vendor['store_id'],
				'name'       => 'Rare Maps',
				'slug'       => $vendor['slug'],
				'visibility' => 'hidden',
				'status'     => 'active',
				'listed'     => false,
			),
			$section['store']
		);

		$this->openMyStore();
		$this->assertTrue( ( (array) $this->data( $this->call( 'GET', '/me' ), 200 )['sections'] )['store']['listed'] );
	}

	public function testAnActorWithoutAStoreGetsNoSection(): void {
		wp_set_current_user( $this->userAs( 'customer' ) );

		$this->assertArrayNotHasKey( 'store', (array) $this->data( $this->call( 'GET', '/me' ), 200 )['sections'] );
	}
}
