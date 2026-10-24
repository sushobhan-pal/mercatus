<?php
/**
 * When a failed event is tried again (API-3, KER-7).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Infrastructure\Outbox;

use Closure;

/**
 * Exponential backoff from 30 seconds, capped at an hour, plus up to 10% jitter so failing events don't retry
 * in lockstep. After 10 failed attempts an event is failed for good and shows in the health report.
 */
final class RetryBackoffPolicy {

	public const MAX_ATTEMPTS = 10;

	private const BASE = 30;

	private const CAP = 3600;

	/**
	 * Random integer between two bounds, inclusive.
	 *
	 * @var Closure(int, int): int
	 */
	private Closure $random;

	/**
	 * Creates the policy.
	 *
	 * @param (Closure(int, int): int)|null $random Random source; random_int() when omitted.
	 */
	public function __construct( ?Closure $random = null ) {
		$this->random = $random ?? static fn( int $min, int $max ): int => random_int( $min, $max );
	}

	/**
	 * Seconds to wait before the next attempt.
	 *
	 * @param int $failed_attempts Attempts that have failed, 1 or more.
	 */
	public function delay( int $failed_attempts ): int {
		$delay = (int) min( self::CAP, self::BASE * ( 2 ** max( 0, $failed_attempts - 1 ) ) );

		return $delay + ( $this->random )( 0, intdiv( $delay, 10 ) );
	}

	/**
	 * Whether an event has failed too often to retry.
	 *
	 * @param int $failed_attempts Attempts that have failed.
	 */
	public function exhausted( int $failed_attempts ): bool {
		return $failed_attempts >= self::MAX_ATTEMPTS;
	}
}
