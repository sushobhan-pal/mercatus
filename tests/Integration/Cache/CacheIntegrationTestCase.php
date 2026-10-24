<?php
/**
 * Base for the cache adapter integration tests.
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Integration\Cache;

use Mercatus\Core\Infrastructure\Cache\WpCachePort;
use Mercatus\Core\Infrastructure\Cache\WpCoordinationPort;
use Mercatus\Core\Tests\Fixtures\Clock\FrozenClock;
use WP_UnitTestCase;

/**
 * Runs a test only under its cache profile (CACHE_MODE from the Docker environment) and starts each test
 * with an empty object cache. Separate adapter instances stand for separate requests: they share the backend
 * but not the per-request memory.
 */
abstract class CacheIntegrationTestCase extends WP_UnitTestCase {

	/**
	 * Cache profile the test needs: none, redis or memcached; empty for any.
	 */
	protected const MODE = '';

	protected FrozenClock $clock;

	public function set_up(): void {
		if ( '' !== static::MODE && static::MODE !== self::mode() ) {
			$this->markTestSkipped( 'Runs under the ' . static::MODE . ' cache profile.' );
		}
		parent::set_up();
		wp_cache_flush();
		$this->clock = new FrozenClock();
	}

	/**
	 * The cache profile this run uses.
	 */
	protected static function mode(): string {
		$mode = getenv( 'CACHE_MODE' );

		return false === $mode || '' === $mode ? 'none' : $mode;
	}

	/**
	 * Whether the run has a persistent object cache.
	 */
	protected static function persistent(): bool {
		return 'none' !== self::mode();
	}

	/**
	 * A cache adapter for a new request.
	 */
	protected function newCachePort(): WpCachePort {
		return new WpCachePort( $this->clock, $this->newCoordinationPort() );
	}

	/**
	 * A coordination adapter for a new request.
	 */
	protected function newCoordinationPort(): WpCoordinationPort {
		return new WpCoordinationPort( $this->clock );
	}
}
