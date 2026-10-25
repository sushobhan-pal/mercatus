<?php
/**
 * The store's status follows its vendor (STO-2, VEN-17).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Integration\Store;

use WP_UnitTestCase;

/**
 * Suspending the vendor hides the store at once, from its page and from the list, even when the page was
 * cached; reinstating brings it back; deleting the vendor's user closes it.
 */
final class StoreFollowsVendorStatusTest extends WP_UnitTestCase {

	use StoreTestHelpers;

	public function testSuspendingHidesTheStoreAndReinstatingRestoresIt(): void {
		$vendor = $this->vendorWithStore();
		$this->openMyStore();
		$this->data( $this->call( 'GET', '/stores/' . $vendor['slug'] ), 200 );

		$this->data( $this->asAdministrator( 'POST', '/vendors/' . $vendor['vendor_id'] . '/suspend', array( 'reason' => 'Late shipments' ) ), 200 );
		wp_set_current_user( 0 );

		$this->assertSame( 'mercatus_store_unknown', $this->data( $this->call( 'GET', '/stores/' . $vendor['slug'] ), 404 )['code'] );
		$this->assertSame( array(), $this->data( $this->call( 'GET', '/stores' ), 200 )['items'] );

		$this->data( $this->asAdministrator( 'POST', '/vendors/' . $vendor['vendor_id'] . '/reinstate' ), 200 );
		$this->assertSame( 'Rare Maps', $this->data( $this->call( 'GET', '/stores/' . $vendor['slug'] ), 200 )['name'] );
	}

	public function testDeletingTheVendorsUserClosesTheStore(): void {
		$vendor = $this->vendorWithStore();
		require_once ABSPATH . 'wp-admin/includes/user.php';

		wp_delete_user( $vendor['user_id'] );

		$this->assertSame( 'closed', $this->data( $this->asAdministrator( 'GET', '/stores/' . $vendor['store_id'], array( 'context' => 'edit' ) ), 200 )['status'] );
	}
}
