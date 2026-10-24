<?php
/**
 * The coordination adapter without a persistent object cache (TEST-3 row 2, PLAT-6).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Integration\Cache;

use Mercatus\Core\Contracts\Cache\CoordinationPort;
use Mercatus\Core\Tests\Contract\Conformance\CoordinationPortConformanceTrait;

/**
 * Counters and locks can't hold across requests here, so the port says so and refuses them.
 *
 * @group cache-none
 */
final class CoordinationPortNoPersistentCacheTest extends CacheIntegrationTestCase {

	use CoordinationPortConformanceTrait;

	protected const MODE = 'none';

	protected function createCoordinationPort(): CoordinationPort {
		return $this->newCoordinationPort();
	}

	public function testReportsItselfUnavailable(): void {
		$port = $this->newCoordinationPort();

		$this->assertFalse( $port->isAvailable() );
		$this->assertNull( $port->increment( 'rate:actor:1', 60 ) );
		$this->assertFalse( $port->acquireLock( 'refresh:x', 30 ) );
	}
}
