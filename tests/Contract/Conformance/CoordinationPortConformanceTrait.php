<?php
/**
 * Conformance tests every CoordinationPort adapter passes (TEST-1 Layer 3).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Contract\Conformance;

use Mercatus\Core\Contracts\Cache\CoordinationPort;

/**
 * Use in a TestCase and implement createCoordinationPort(). Each call must return a port with no counters
 * or locks. The tests check whichever behaviour the port reports through isAvailable().
 */
trait CoordinationPortConformanceTrait {

	/**
	 * Returns a port with no counters or locks.
	 */
	abstract protected function createCoordinationPort(): CoordinationPort;

	public function testCoordinationPortReportsAvailabilityConsistently(): void {
		$port = $this->createCoordinationPort();

		$this->assertSame( $port->isAvailable(), $port->isAvailable() );
	}

	public function testCoordinationPortCountsWithinAWindow(): void {
		$port = $this->createCoordinationPort();

		if ( ! $port->isAvailable() ) {
			$this->assertNull( $port->increment( 'rate:actor:1', 60 ) );
			return;
		}

		$this->assertSame( 1, $port->increment( 'rate:actor:1', 60 ) );
		$this->assertSame( 2, $port->increment( 'rate:actor:1', 60 ) );
		$this->assertSame( 1, $port->increment( 'rate:actor:2', 60 ) );
	}

	public function testCoordinationPortLocksAreExclusiveUntilReleased(): void {
		$port = $this->createCoordinationPort();

		if ( ! $port->isAvailable() ) {
			$this->assertFalse( $port->acquireLock( 'refresh:product:42', 30 ) );
			$port->releaseLock( 'refresh:product:42' );
			return;
		}

		$this->assertTrue( $port->acquireLock( 'refresh:product:42', 30 ) );
		$this->assertFalse( $port->acquireLock( 'refresh:product:42', 30 ) );
		$this->assertTrue( $port->acquireLock( 'refresh:product:43', 30 ) );

		$port->releaseLock( 'refresh:product:42' );

		$this->assertTrue( $port->acquireLock( 'refresh:product:42', 30 ) );
	}

	public function testCoordinationPortReleasesAnUnheldLockQuietly(): void {
		$port = $this->createCoordinationPort();

		$port->releaseLock( 'never-taken' );

		$this->assertSame( $port->isAvailable(), $port->acquireLock( 'never-taken', 30 ) );
	}
}
