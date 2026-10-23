<?php
/**
 * Best-effort cache (PLAT-3, PLAT-5).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Contracts\Cache;

/**
 * The only way Mercatus code caches. Losing a cached value only costs speed; nothing that must be correct
 * depends on this port (PLAT-6). Groups are mercatus_* names and TTLs are seconds. Null is never stored, so
 * a null result always means a miss; storing null or a TTL below 1 throws InvalidArgumentException.
 */
interface CachePort {

	/**
	 * Returns a cached value, or null on a miss.
	 *
	 * @param string $group Cache group.
	 * @param string $key   Key within the group.
	 * @phpstan-impure
	 */
	public function get( string $group, string $key ): mixed;

	/**
	 * Returns the values found for several keys in one round trip; misses are absent from the result.
	 *
	 * @param string       $group Cache group.
	 * @param list<string> $keys  Keys within the group.
	 * @return array<string, mixed>
	 * @phpstan-impure
	 */
	public function getMany( string $group, array $keys ): array;

	/**
	 * Stores a value.
	 *
	 * @param string $group Cache group.
	 * @param string $key   Key within the group.
	 * @param mixed  $value Any value except null.
	 * @param int    $ttl   Seconds, at least 1.
	 * @throws \InvalidArgumentException When the value is null or the TTL is below 1.
	 */
	public function set( string $group, string $key, mixed $value, int $ttl ): void;

	/**
	 * Stores several values in one round trip.
	 *
	 * @param string               $group  Cache group.
	 * @param array<string, mixed> $values Values keyed by key; none may be null.
	 * @param int                  $ttl    Seconds, at least 1.
	 * @throws \InvalidArgumentException When a value is null or the TTL is below 1.
	 */
	public function setMany( string $group, array $values, int $ttl ): void;

	/**
	 * Removes a value.
	 *
	 * @param string $group Cache group.
	 * @param string $key   Key within the group.
	 */
	public function delete( string $group, string $key ): void;

	/**
	 * Returns the cached value, or computes, stores and returns it. With a persistent cache, one request
	 * refreshes an expired hot value while others are served the stale one (PLAT-3).
	 *
	 * @template T
	 * @param string        $group   Cache group.
	 * @param string        $key     Key within the group.
	 * @param int           $ttl     Seconds, at least 1.
	 * @param callable(): T $compute Produces the value; must not return null.
	 * @return T
	 * @throws \InvalidArgumentException When the TTL is below 1 or $compute returns null.
	 * @phpstan-impure
	 */
	public function remember( string $group, string $key, int $ttl, callable $compute ): mixed;

	/**
	 * Returns the current revision of a scope such as "product:42". A new scope starts from the current
	 * Unix time, never from 1, so an evicted pointer can't revive old entries (PLAT-5).
	 *
	 * @param string $scope Revision scope.
	 * @phpstan-impure
	 */
	public function revision( string $scope ): int;

	/**
	 * Moves a scope to a newer revision and returns it; keys built on the old revision become unreachable.
	 *
	 * @param string $scope Revision scope.
	 * @phpstan-impure
	 */
	public function bumpRevision( string $scope ): int;

	/**
	 * Makes every existing key in a group unreachable by bumping the group's revision (PLAT-5).
	 *
	 * @param string $group Cache group.
	 */
	public function flushGroup( string $group ): void;
}
