<?php
/**
 * Requests are counted per actor, bucket and minute (TEST-3 row 4, PLAT-8, IDN-19, TM-37).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Unit\Identity;

use Mercatus\Core\Identity\Application\IdentitySettings;
use Mercatus\Core\Identity\Application\RateLimiter;
use Mercatus\Core\Tests\Contract\Fakes\InMemoryCoordinationPort;
use Mercatus\Core\Tests\Fixtures\Identity\FixedSettings;
use PHPUnit\Framework\TestCase;

/**
 * Each kind of actor has its own read and write limits, a route can be tighter, and without cross-request
 * counters nothing is limited.
 */
final class RateLimiterTest extends TestCase {

	private function limiter( bool $available = true ): RateLimiter {
		return new RateLimiter(
			new InMemoryCoordinationPort( $available ),
			new FixedSettings(
				array(
					IdentitySettings::RATE_LIMIT_VISITOR_READS  => 2,
					IdentitySettings::RATE_LIMIT_VISITOR_WRITES => 1,
					IdentitySettings::RATE_LIMIT_USER_READS     => 5,
					IdentitySettings::RATE_LIMIT_USER_WRITES    => 3,
					IdentitySettings::RATE_LIMIT_CLIENT_READS   => 9,
					IdentitySettings::RATE_LIMIT_CLIENT_WRITES  => 4,
				)
			),
			static fn(): int => 1_800_000_015
		);
	}

	public function testRefusesOnceAVisitorPassesTheirReadLimit(): void {
		$limiter = $this->limiter();

		$first  = $limiter->hit( 'ip:203.0.113.5', 'visitor', false, 'GET /x' );
		$second = $limiter->hit( 'ip:203.0.113.5', 'visitor', false, 'GET /x' );
		$third  = $limiter->hit( 'ip:203.0.113.5', 'visitor', false, 'GET /x' );

		$this->assertSame( array( true, 2, 1, 45 ), array( $first->allowed, $first->limit, $first->remaining, $first->reset ) );
		$this->assertSame( array( true, 0 ), array( $second->allowed, $second->remaining ) );
		$this->assertFalse( $third->allowed );
		$this->assertTrue( $limiter->hit( 'ip:203.0.113.6', 'visitor', false, 'GET /x' )->allowed, 'Another visitor has their own count.' );
	}

	public function testReadsAndWritesCountSeparately(): void {
		$limiter = $this->limiter();

		$this->assertTrue( $limiter->hit( 'ip:203.0.113.5', 'visitor', true, 'POST /x' )->allowed );
		$this->assertFalse( $limiter->hit( 'ip:203.0.113.5', 'visitor', true, 'POST /x' )->allowed );
		$this->assertTrue( $limiter->hit( 'ip:203.0.113.5', 'visitor', false, 'GET /x' )->allowed );
	}

	public function testEachKindOfActorHasItsOwnLimits(): void {
		$limiter = $this->limiter();

		$this->assertSame( 5, $limiter->hit( 'user:7', 'user', false, 'GET /x' )->limit );
		$this->assertSame( 4, $limiter->hit( 'client:3', 'client', true, 'POST /x' )->limit );
	}

	public function testARoutesOwnLimitIsTighter(): void {
		$limiter = $this->limiter();

		$this->assertTrue( $limiter->hit( 'user:7', 'user', true, 'POST /reviews', 1 )->allowed );
		$refused = $limiter->hit( 'user:7', 'user', true, 'POST /reviews', 1 );

		$this->assertSame( array( false, 1 ), array( $refused->allowed, $refused->limit ) );
		$this->assertTrue( $limiter->hit( 'user:7', 'user', true, 'POST /elsewhere' )->allowed, 'Other routes keep the general limit.' );
	}

	public function testNothingIsLimitedWithoutCrossRequestCounters(): void {
		$limiter = $this->limiter( false );

		for ( $i = 0; $i < 5; $i++ ) {
			$decision = $limiter->hit( 'ip:203.0.113.5', 'visitor', true, 'POST /x' );
			$this->assertTrue( $decision->allowed );
			$this->assertNull( $decision->limit );
		}
	}
}
