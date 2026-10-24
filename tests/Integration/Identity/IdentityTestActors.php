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
use Mercatus\Core\Shared\Clock\Clock;
use Mercatus\Core\Vendor\Domain\VendorLifecycles;
use Mercatus\Core\Vendor\Infrastructure\ApplicationStore;
use Mercatus\Core\Vendor\Infrastructure\VendorStore;
use RuntimeException;

/**
 * Users as each actor type, through the real claim sources: WordPress roles, manager appointments, and
 * core.vendor's vendors (IDN-20).
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
			self::approveVendor( $user_id );
		}

		return $user_id;
	}

	/**
	 * Makes a user an active vendor: an approved first attempt and its vendor row, as approval writes them.
	 *
	 * @param int $user_id User.
	 * @return int Vendor ID.
	 */
	private static function approveVendor( int $user_id ): int {
		$services     = self::kernel()->container();
		$now          = $services->get( Clock::class )->now();
		$applications = $services->get( ApplicationStore::class );
		$application  = $applications->insert( $user_id, 1, 'Store of user ' . $user_id, array(), $now );
		$applications->decide( $application, 1, VendorLifecycles::APPROVED, null, 0, $now );

		return $services->get( VendorStore::class )->insert( $user_id, $application, $now );
	}

	/**
	 * A user's vendor ID.
	 *
	 * @param int $user_id User.
	 * @throws RuntimeException When the user isn't a vendor.
	 */
	private static function vendorOf( int $user_id ): int {
		return self::kernel()->container()->get( VendorStore::class )->byUserId( $user_id )->id ?? throw new RuntimeException( 'Not a vendor.' );
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
