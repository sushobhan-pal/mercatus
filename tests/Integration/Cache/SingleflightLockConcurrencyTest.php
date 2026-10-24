<?php
/**
 * One request refreshes a stale value while others are served it (TEST-3 row 2, PLAT-3).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Integration\Cache;

use Mercatus\Core\Infrastructure\Cache\WpCachePort;

/**
 * Separate adapter instances play concurrent requests. With a persistent cache, a request that finds the
 * refresh lock taken gets the stale value without computing; once the lock is free, the next request
 * refreshes for everyone. Without one, every request recomputes a stale value.
 */
final class SingleflightLockConcurrencyTest extends CacheIntegrationTestCase {

	private int $computed = 0;

	public function testOnlyTheLockHolderRefreshesAStaleValue(): void {
		$this->newCachePort()->remember( 'mercatus_tests', 'hot', 60, $this->builder() );
		$this->clock->advance( 61 );

		if ( ! self::persistent() ) {
			$this->assertSame( 'value-2', $this->newCachePort()->remember( 'mercatus_tests', 'hot', 60, $this->builder() ) );
			return;
		}

		// Another request is already refreshing.
		$refresher = $this->newCoordinationPort();
		$this->assertTrue( $refresher->acquireLock( WpCachePort::refreshLock( 'mercatus_tests', 'hot' ), 30 ) );

		$this->assertSame( 'value-1', $this->newCachePort()->remember( 'mercatus_tests', 'hot', 60, $this->builder() ) );
		$this->assertSame( 'value-1', $this->newCachePort()->remember( 'mercatus_tests', 'hot', 60, $this->builder() ) );
		$this->assertSame( 1, $this->computed );

		$refresher->releaseLock( WpCachePort::refreshLock( 'mercatus_tests', 'hot' ) );

		$this->assertSame( 'value-2', $this->newCachePort()->remember( 'mercatus_tests', 'hot', 60, $this->builder() ) );
		$this->assertSame( 'value-2', $this->newCachePort()->get( 'mercatus_tests', 'hot' ) );
		$this->assertTrue( $this->newCoordinationPort()->acquireLock( WpCachePort::refreshLock( 'mercatus_tests', 'hot' ), 30 ), 'The refresh released its lock.' );
	}

	/**
	 * A compute callback that counts its calls.
	 *
	 * @return callable(): string
	 */
	private function builder(): callable {
		return function (): string {
			++$this->computed;
			return 'value-' . $this->computed;
		};
	}
}
