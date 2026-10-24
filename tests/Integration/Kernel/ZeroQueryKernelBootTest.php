<?php
/**
 * Boot runs no queries of its own on an installed site (TEST-3 row 1, core.kernel §9).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Integration\Kernel;

use Mercatus\Core\Kernel\Kernel;
use Mercatus\Core\Kernel\KernelSettings;
use WP_UnitTestCase;

/**
 * Module states and settings live in small autoloaded options, which WordPress loads with its own single
 * query before plugins run.
 */
final class ZeroQueryKernelBootTest extends WP_UnitTestCase {

	public function testBootingAnInstalledSiteRunsNoQueriesOfItsOwn(): void {
		global $wpdb;

		// The first boot after install creates any missing kernel options.
		Kernel::create( MERCATUS_VERSION )->start();
		self::startNextRequest();
		$before = $wpdb->num_queries;

		$kernel = Kernel::create( MERCATUS_VERSION );
		$kernel->start();
		$retention = $kernel->settings()->int( KernelSettings::OUTBOX_RETENTION_DAYS );

		$this->assertSame( 0, $wpdb->num_queries - $before );
		$this->assertSame( 7, $retention );
		$this->assertSame( array( 'core.kernel', 'core.identity', 'core.vendor', 'core.store' ), array_values( array_filter( $kernel->resolution()->activeIds(), static fn( string $id ): bool => str_starts_with( $id, 'core.' ) ) ) );
	}

	/**
	 * Drops WordPress's in-request option caches, so the next reads behave like a new request after WordPress
	 * has loaded its autoloaded options.
	 */
	private static function startNextRequest(): void {
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		wp_load_alloptions();
	}
}
