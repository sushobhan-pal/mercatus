<?php
/**
 * Creates the test actors the identity tests share.
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Integration\Identity;

use Mercatus\Core\Identity\Application\IdentitySettings;
use Mercatus\Core\Identity\Infrastructure\ManagerAppointments;
use Mercatus\Core\Kernel\Kernel;
use RuntimeException;

/**
 * Users as each actor type, through the real claim sources: WordPress roles, manager appointments, and the
 * test-only `pro.testactors` claims for vendors.
 */
trait IdentityTestActors {

	/**
	 * A new user acting as the given actor.
	 *
	 * @param string $actor anonymous, customer, administrator, marketplace_manager, vendor_owner or other_vendor_owner.
	 */
	private function userAs( string $actor ): int {
		if ( 'anonymous' === $actor ) {
			return 0;
		}
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' === $actor ? 'administrator' : 'subscriber' ) );
		$this->assertIsInt( $user_id );
		if ( 'marketplace_manager' === $actor ) {
			self::kernel()->container()->get( ManagerAppointments::class )->appoint( $user_id );
		} elseif ( 'vendor_owner' === $actor || 'other_vendor_owner' === $actor ) {
			update_user_meta( $user_id, 'mercatus_test_vendor', 'vendor_owner' === $actor ? 12 : 99 );
		}

		return $user_id;
	}

	/**
	 * Sets the permissions every manager holds.
	 *
	 * @param list<string> $permissions Permission names.
	 */
	private static function grantManagers( array $permissions ): void {
		self::kernel()->settings()->set( IdentitySettings::MANAGER_PERMISSIONS, implode( ',', $permissions ) );
	}

	/**
	 * The site's booted kernel.
	 *
	 * @throws RuntimeException When it didn't boot.
	 */
	private static function kernel(): Kernel {
		return Kernel::instance() ?? throw new RuntimeException( 'The site kernel did not boot.' );
	}
}
