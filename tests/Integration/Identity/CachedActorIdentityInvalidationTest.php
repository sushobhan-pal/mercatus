<?php
/**
 * A cached identity changes as soon as who the user is changes (TEST-3 row 4, SEC-2, G-CACHE-4, TM-8).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Integration\Identity;

use Mercatus\Core\Contracts\Cache\CachePort;
use Mercatus\Core\Contracts\Identity\ActorType;
use Mercatus\Core\Contracts\Identity\IdentityRevisions;
use Mercatus\Core\Contracts\Settings\SettingsReader;
use Mercatus\Core\Identity\Application\IdentityResolver;
use Mercatus\Core\Identity\Application\ModuleExtensions;
use Mercatus\Core\Identity\Infrastructure\ManagerAppointments;
use Mercatus\Core\Shared\Clock\Clock;
use Mercatus\Core\Vendor\Domain\VendorLifecycles;
use Mercatus\Core\Vendor\Infrastructure\VendorStore;
use WP_UnitTestCase;
use WP_User;

/**
 * Each "request" is a new resolver over the site's cache, so only the cache carries identities between them.
 * A suspension that forgets the bump keeps serving the old identity, which is why owning modules must bump
 * (IDN-2).
 */
final class CachedActorIdentityInvalidationTest extends WP_UnitTestCase {

	use IdentityTestActors;

	public function tear_down(): void {
		self::grantManagers( array() );
		parent::tear_down();
	}

	public function testASuspendedVendorLosesTheirStoreOnceTheRevisionMoves(): void {
		$user_id   = $this->userAs( 'vendor_owner' );
		$vendor_id = self::vendorOf( $user_id );
		$this->assertSame( $vendor_id, $this->nextRequest()->resolve( $user_id )->vendorId );

		// A status change that forgets the bump, as a careless module would write it.
		$vendors = self::kernel()->container()->get( VendorStore::class );
		$vendors->transition( $vendor_id, 1, VendorLifecycles::SUSPENDED, 'Testing', self::kernel()->container()->get( Clock::class )->now() );
		$this->assertSame( $vendor_id, $this->nextRequest()->resolve( $user_id )->vendorId, 'Without a bump the cached identity stays.' );

		self::kernel()->container()->get( IdentityRevisions::class )->bump( $user_id );
		$identity = $this->nextRequest()->resolve( $user_id );
		$this->assertSame( ActorType::Customer, $identity->type );
		$this->assertNull( $identity->vendorId );
	}

	public function testSuspendingThroughTheEndpointTakesEffectInTheSameRequest(): void {
		$user_id   = $this->userAs( 'vendor_owner' );
		$vendor_id = self::vendorOf( $user_id );
		$this->nextRequest()->resolve( $user_id );

		wp_set_current_user( $this->userAs( 'administrator' ) );
		$request = new \WP_REST_Request( 'POST', '/mercatus/v1/vendors/' . $vendor_id . '/suspend' );
		$request->set_body_params( array( 'reason' => 'Late shipments' ) );
		$this->assertSame( 200, rest_do_request( $request )->get_status() );

		$this->assertSame( ActorType::Customer, $this->nextRequest()->resolve( $user_id )->type, 'Suspending bumps the identity (TM-8).' );
	}

	public function testARoleChangeInWordPressResolvesAfresh(): void {
		$user_id = $this->userAs( 'customer' );
		$this->assertSame( ActorType::Customer, $this->nextRequest()->resolve( $user_id )->type );

		$user = get_user_by( 'id', $user_id );
		$this->assertInstanceOf( WP_User::class, $user );
		$user->set_role( 'administrator' );

		$this->assertSame( ActorType::Administrator, $this->nextRequest()->resolve( $user_id )->type );
	}

	public function testAppointingAndRemovingAManagerTakesEffectAtOnce(): void {
		$user_id  = $this->userAs( 'customer' );
		$managers = self::kernel()->container()->get( ManagerAppointments::class );
		$this->nextRequest()->resolve( $user_id );

		$managers->appoint( $user_id );
		$this->assertSame( ActorType::MarketplaceManager, $this->nextRequest()->resolve( $user_id )->type );

		$managers->remove( $user_id );
		$this->assertSame( ActorType::Customer, $this->nextRequest()->resolve( $user_id )->type );
	}

	public function testChangingTheManagersPermissionsNeedsNoBump(): void {
		$user_id = $this->userAs( 'marketplace_manager' );
		$this->assertSame( array(), $this->nextRequest()->resolve( $user_id )->permissions );

		self::grantManagers( array( 'kernel.health.view' ) );

		$this->assertSame( array( 'kernel.health.view' ), $this->nextRequest()->resolve( $user_id )->permissions );
	}

	/**
	 * A resolver as the next request builds it, sharing only the cache.
	 */
	private function nextRequest(): IdentityResolver {
		$services = self::kernel()->container();

		return new IdentityResolver( $services->get( CachePort::class ), $services->get( ModuleExtensions::class ), $services->get( SettingsReader::class ) );
	}
}
