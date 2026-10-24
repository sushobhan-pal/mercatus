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
		$user_id = $this->userAs( 'vendor_owner' );
		$this->assertSame( 12, $this->nextRequest()->resolve( $user_id )->vendorId );

		delete_user_meta( $user_id, 'mercatus_test_vendor' );
		$this->assertSame( 12, $this->nextRequest()->resolve( $user_id )->vendorId, 'Without a bump the cached identity stays.' );

		self::kernel()->container()->get( IdentityRevisions::class )->bump( $user_id );
		$identity = $this->nextRequest()->resolve( $user_id );
		$this->assertSame( ActorType::Customer, $identity->type );
		$this->assertNull( $identity->vendorId );
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
