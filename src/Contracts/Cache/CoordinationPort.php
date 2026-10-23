<?php
/**
 * Cross-request counters and locks (PLAT-6).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Contracts\Cache;

/**
 * Atomic counters and locks that hold across requests when a persistent object cache is present. Without
 * one they're unavailable and best-effort users, such as rate limiting (PLAT-8), switch off. Correctness
 * never depends on this port.
 */
interface CoordinationPort {

	/**
	 * Whether counters and locks hold across requests.
	 */
	public function isAvailable(): bool;

	/**
	 * Adds one to a counter in the current fixed window and returns the new count, or null when unavailable.
	 *
	 * @param string $key    Counter key.
	 * @param int    $window Window length in seconds, at least 1.
	 * @phpstan-impure
	 */
	public function increment( string $key, int $window ): ?int;

	/**
	 * Takes a lock without waiting. Returns false when it's already held or coordination is unavailable.
	 * The lock expires after $ttl seconds if it isn't released.
	 *
	 * @param string $key Lock key.
	 * @param int    $ttl Seconds, at least 1.
	 * @phpstan-impure
	 */
	public function acquireLock( string $key, int $ttl ): bool;

	/**
	 * Releases a lock taken with acquireLock().
	 *
	 * @param string $key Lock key.
	 */
	public function releaseLock( string $key ): void;
}
