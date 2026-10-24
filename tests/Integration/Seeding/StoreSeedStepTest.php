<?php
/**
 * core.store's scale seed step (core.store §9, PLAT-10, KER-12).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Integration\Seeding;

use Mercatus\Core\Database\Seeders\ScaleBenchmarkSeeder;
use Mercatus\Core\Database\Seeders\SeedContext;
use Mercatus\Core\Database\Seeders\SeedStep;
use Mercatus\Core\Kernel\Kernel;
use Mercatus\Core\Store\Infrastructure\StoreRepository;
use Mercatus\Core\Vendor\Infrastructure\VendorStore;
use RuntimeException;
use WP_UnitTestCase;
use WP_User;

/**
 * After core.vendor's step, every seeded vendor's store is open, with a profile, a city and a geohash cell.
 */
final class StoreSeedStepTest extends WP_UnitTestCase {

	public function testOpensAndFillsInEverySeededStore(): void {
		$context = static fn( string $module ): SeedContext => new SeedContext( $module, ScaleBenchmarkSeeder::SEED, 0.002, static function (): void {}, static function (): void {} );
		self::step( 'core.vendor' )->run( $context( 'core.vendor' ) );

		self::step( 'core.store' )->run( $context( 'core.store' ) );

		$services = self::kernel()->container();
		$count    = $context( 'core.store' )->count( 'vendors' );
		for ( $n = 1; $n <= $count; $n++ ) {
			$user = get_user_by( 'login', sprintf( 'seed-vendor-%04d', $n ) );
			$this->assertInstanceOf( WP_User::class, $user );
			$pair = $services->get( StoreRepository::class )->byVendorId( (int) $services->get( VendorStore::class )->byUserId( $user->ID )?->id );
			$this->assertNotNull( $pair );
			$this->assertTrue( $pair[0]->isListed() );
			$this->assertNotSame( '', $pair[0]->text( 'tagline' ) );
			$this->assertNotNull( $pair[1] );
			$this->assertNotSame( '', $pair[1]->address['city'] );
			$this->assertMatchesRegularExpression( '/^[0-9b-hjkmnp-z]{6}$/', (string) $pair[1]->geoCell );
		}
		$this->assertSame( 0, get_current_user_id() );
	}

	/**
	 * A step file.
	 *
	 * @param string $module Module ID.
	 * @throws RuntimeException When it isn't a step.
	 */
	private static function step( string $module ): SeedStep {
		$step = require dirname( __DIR__, 3 ) . '/database/seeders/steps/' . $module . '.php';

		return $step instanceof SeedStep ? $step : throw new RuntimeException( 'Not a step.' );
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
