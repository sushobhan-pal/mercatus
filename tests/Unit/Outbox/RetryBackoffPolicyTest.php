<?php
/**
 * When a failed event is tried again (TEST-3 row 3, KER-7).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Unit\Outbox;

use Mercatus\Core\Infrastructure\Outbox\RetryBackoffPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Doubling from 30 seconds, capped at an hour, up to 10% jitter, and failure after 10 attempts.
 */
final class RetryBackoffPolicyTest extends TestCase {

	public function testDoublesFromThirtySecondsUpToAnHour(): void {
		// The lowest jitter, so the base delay shows.
		$policy = new RetryBackoffPolicy( static fn( int $min ): int => $min );

		$this->assertSame( array( 30, 60, 120, 240, 480, 960, 1920, 3600, 3600 ), array_map( array( $policy, 'delay' ), range( 1, 9 ) ) );
	}

	public function testAddsAtMostTenPercentJitter(): void {
		$bounds = array();
		$policy = new RetryBackoffPolicy(
			static function ( int $min, int $max ) use ( &$bounds ): int {
				$bounds[] = array( $min, $max );
				return $max;
			}
		);

		$this->assertSame( 66, $policy->delay( 2 ) );
		$this->assertSame( array( array( 0, 6 ) ), $bounds );
	}

	public function testGivesUpAfterTenFailedAttempts(): void {
		$policy = new RetryBackoffPolicy();

		$this->assertFalse( $policy->exhausted( 9 ) );
		$this->assertTrue( $policy->exhausted( RetryBackoffPolicy::MAX_ATTEMPTS ) );
	}

	public function testRealJitterStaysInBounds(): void {
		$delay = ( new RetryBackoffPolicy() )->delay( 3 );

		$this->assertGreaterThanOrEqual( 120, $delay );
		$this->assertLessThanOrEqual( 132, $delay );
	}
}
