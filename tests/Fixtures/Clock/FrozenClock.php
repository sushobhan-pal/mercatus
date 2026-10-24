<?php
/**
 * A clock that tests move by hand.
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Fixtures\Clock;

use DateTimeImmutable;
use DateTimeZone;
use Mercatus\Core\Shared\Clock\Clock;

/**
 * Starts at the real time unless given one, and moves only when advanced.
 */
final class FrozenClock implements Clock {

	private DateTimeImmutable $now;

	/**
	 * Creates the clock.
	 *
	 * @param DateTimeImmutable|null $now Starting time; now when omitted.
	 */
	public function __construct( ?DateTimeImmutable $now = null ) {
		$this->now = $now ?? new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
	}

	public function now(): DateTimeImmutable {
		return $this->now;
	}

	/**
	 * Moves the clock forward.
	 *
	 * @param int $seconds Seconds.
	 */
	public function advance( int $seconds ): void {
		$this->now = $this->now->modify( '+' . $seconds . ' seconds' );
	}
}
