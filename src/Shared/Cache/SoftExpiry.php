<?php
/**
 * Soft expiry for hot cache entries (PLAT-3, KER-6).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Shared\Cache;

use DateTimeImmutable;

/**
 * Every cached value is stored in an envelope with an optional soft expiry. Past the soft expiry the value is
 * stale: one caller refreshes it while others are served the stale value. The backend keeps the entry until a
 * hard TTL five times longer, so a stale value exists to serve.
 */
final class SoftExpiry {

	public const HARD_TTL_FACTOR = 5;

	/**
	 * An envelope without a soft expiry, for set().
	 *
	 * @param mixed $value Value.
	 * @return array{v: mixed, s: int}
	 */
	public static function plain( mixed $value ): array {
		return array(
			'v' => $value,
			's' => 0,
		);
	}

	/**
	 * An envelope that turns stale after $ttl seconds, for remember().
	 *
	 * @param mixed             $value Value.
	 * @param int               $ttl   Soft TTL in seconds.
	 * @param DateTimeImmutable $now   Current time.
	 * @return array{v: mixed, s: int}
	 */
	public static function wrap( mixed $value, int $ttl, DateTimeImmutable $now ): array {
		return array(
			'v' => $value,
			's' => $now->getTimestamp() + $ttl,
		);
	}

	/**
	 * How long the backend keeps an entry with this soft TTL.
	 *
	 * @param int $ttl Soft TTL in seconds.
	 */
	public static function hardTtl( int $ttl ): int {
		return $ttl * self::HARD_TTL_FACTOR;
	}

	/**
	 * Whether a stored value is an envelope.
	 *
	 * @param mixed $stored Value read from the backend.
	 * @phpstan-assert-if-true array{v: mixed, s: int} $stored
	 */
	public static function isEnvelope( mixed $stored ): bool {
		return is_array( $stored ) && array_key_exists( 'v', $stored ) && isset( $stored['s'] ) && is_int( $stored['s'] ) && 2 === count( $stored );
	}

	/**
	 * Whether an envelope's value is still fresh.
	 *
	 * @param array{v: mixed, s: int} $envelope Envelope.
	 * @param DateTimeImmutable       $now      Current time.
	 */
	public static function isFresh( array $envelope, DateTimeImmutable $now ): bool {
		return 0 === $envelope['s'] || $now->getTimestamp() < $envelope['s'];
	}
}
