<?php
/**
 * core.vendor's scale seed step (core.vendor §9, PLAT-10, KER-12).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Integration\Seeding;

use Mercatus\Core\Database\Seeders\ScaleBenchmarkSeeder;
use Mercatus\Core\Database\Seeders\SeedContext;
use Mercatus\Core\Kernel\Kernel;
use Mercatus\Core\Vendor\Application\VendorSettings;
use Mercatus\Core\Vendor\Infrastructure\ApplicationStore;
use Mercatus\Core\Vendor\Infrastructure\VendorStore;
use RuntimeException;
use WP_UnitTestCase;
use WP_User;

/**
 * At a small scale, the step creates its share of vendors as real approvals (a customer, an approved attempt
 * and an active vendor each), sends no email, and leaves automatic approval as it found it.
 */
final class VendorSeedStepTest extends WP_UnitTestCase {

	public function testCreatesApprovedVendorsThroughRegistration(): void {
		reset_phpmailer_instance();
		$context = new SeedContext( 'core.vendor', ScaleBenchmarkSeeder::SEED, 0.002, static function (): void {}, static function (): void {} );

		$this->step()->run( $context );

		$services = self::kernel()->container();
		$count    = $context->count( 'vendors' );
		for ( $n = 1; $n <= $count; $n++ ) {
			$user = get_user_by( 'login', sprintf( 'seed-vendor-%04d', $n ) );
			$this->assertInstanceOf( WP_User::class, $user );
			$this->assertSame( array( 'customer' ), $user->roles );
			$this->assertSame( 'active', $services->get( VendorStore::class )->byUserId( $user->ID )?->status );
			$attempts = $services->get( ApplicationStore::class )->history( $user->ID );
			$this->assertCount( 1, $attempts );
			$this->assertSame( 'approved', $attempts[0]->status );
			$this->assertMatchesRegularExpression( '/^[A-Z][a-z]+ [A-Z][a-z]+ ' . sprintf( '%04d', $n ) . '$/', $attempts[0]->storeName );
		}
		$this->assertSame( 4, $count );
		$this->assertFalse( self::kernel()->settings()->bool( VendorSettings::AUTO_APPROVE ) );
		$this->assertSame( 0, get_current_user_id() );
		$mailer = tests_retrieve_phpmailer_instance();
		$this->assertInstanceOf( \MockPHPMailer::class, $mailer );
		$this->assertSame( array(), $mailer->mock_sent );
	}

	/**
	 * The step file.
	 *
	 * @throws RuntimeException When it isn't a step.
	 */
	private function step(): \Mercatus\Core\Database\Seeders\SeedStep {
		$step = require dirname( __DIR__, 3 ) . '/database/seeders/steps/core.vendor.php';

		return $step instanceof \Mercatus\Core\Database\Seeders\SeedStep ? $step : throw new RuntimeException( 'Not a step.' );
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
