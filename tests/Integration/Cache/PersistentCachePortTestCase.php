<?php
/**
 * Shared tests for the cache adapters on a persistent backend.
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Integration\Cache;

use Mercatus\Core\Contracts\Cache\CachePort;
use Mercatus\Core\Contracts\Cache\CoordinationPort;
use Mercatus\Core\Tests\Contract\Conformance\CachePortConformanceTrait;
use Mercatus\Core\Tests\Contract\Conformance\CoordinationPortConformanceTrait;

/**
 * Both conformance traits, plus what a persistent backend adds: values, revisions and group flushes reach
 * other requests, and coordination is available.
 */
abstract class PersistentCachePortTestCase extends CacheIntegrationTestCase {

	use CachePortConformanceTrait;
	use CoordinationPortConformanceTrait;

	protected function createCachePort(): CachePort {
		wp_cache_flush();

		return $this->newCachePort();
	}

	protected function createCoordinationPort(): CoordinationPort {
		wp_cache_flush();

		return $this->newCoordinationPort();
	}

	public function testValuesReachOtherRequests(): void {
		$this->newCachePort()->set( 'mercatus_tests', 'key', array( 'id' => 42 ), 60 );

		$this->assertSame( array( 'id' => 42 ), $this->newCachePort()->get( 'mercatus_tests', 'key' ) );
	}

	public function testRevisionsAndGroupFlushesReachOtherRequests(): void {
		$first = $this->newCachePort();
		$first->set( 'mercatus_tests', 'key', 'old', 60 );
		$revision = $first->revision( 'product:42' );

		$second = $this->newCachePort();
		$this->assertSame( $revision, $second->revision( 'product:42' ) );
		$second->flushGroup( 'mercatus_tests' );

		$this->assertNull( $this->newCachePort()->get( 'mercatus_tests', 'key' ) );
	}

	public function testCoordinationIsAvailable(): void {
		$this->assertTrue( $this->newCoordinationPort()->isAvailable() );
	}
}
