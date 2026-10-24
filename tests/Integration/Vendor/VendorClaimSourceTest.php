<?php
/**
 * Only an active vendor owns a store (IDN-3, VEN-4).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Integration\Vendor;

use Mercatus\Core\Contracts\Identity\ActorType;
use Mercatus\Core\Vendor\Infrastructure\VendorClaimSource;
use Mercatus\Core\Vendor\Infrastructure\VendorStore;
use WP_UnitTestCase;

/**
 * One indexed query; applicants and suspended vendors claim nothing.
 */
final class VendorClaimSourceTest extends WP_UnitTestCase {

	use VendorTestHelpers;

	public function testOnlyAnActiveVendorClaimsTheirStoreInOneQuery(): void {
		global $wpdb;
		$user = $this->userAs( 'customer' );
		wp_set_current_user( $user );
		$id     = (int) $this->data( $this->registerVendor(), 201 )['application']['id'];
		$source = self::kernel()->container()->get( VendorClaimSource::class );
		$this->assertSame( array(), $source->claims( $user ), 'An applicant claims nothing.' );

		$this->data( $this->asAdministrator( 'POST', '/vendor-applications/' . $id . '/approve' ), 200 );
		$before = $wpdb->num_queries;
		$claims = $source->claims( $user );
		$this->assertSame( 1, $wpdb->num_queries - $before );
		$this->assertSame( ActorType::VendorOwner, $claims[0]->type ?? null );

		$vendor = self::kernel()->container()->get( VendorStore::class )->byUserId( $user );
		$this->assertNotNull( $vendor );
		$this->data( $this->asAdministrator( 'POST', '/vendors/' . $vendor->id . '/suspend', array( 'reason' => 'Paused' ) ), 200 );
		$this->assertSame( array(), $source->claims( $user ) );
	}
}
