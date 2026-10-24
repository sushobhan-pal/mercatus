<?php
/**
 * CachePort over WordPress's object cache (PLAT-3, PLAT-5, KER-6).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Infrastructure\Cache;

use InvalidArgumentException;
use Mercatus\Core\Contracts\Cache\CachePort;
use Mercatus\Core\Contracts\Cache\CoordinationPort;
use Mercatus\Core\Shared\Cache\SoftExpiry;
use Mercatus\Core\Shared\Clock\Clock;

/**
 * Uses only `wp_cache_*`, so it works with Redis, Memcached or no persistent cache at all.
 *
 * - Values are stored in a SoftExpiry envelope, so false is storable and a miss is unambiguous.
 * - Keys carry their group's revision; flushGroup() bumps it instead of flushing (PLAT-5).
 * - Revision pointers are seeded from the clock in milliseconds with add() before incr(), so an evicted
 *   pointer comes back newer than any value it ever had.
 * - A per-request memory layer saves repeat network hops.
 * - remember() serves a stale value while the one caller holding the CoordinationPort lock refreshes it.
 *   Without coordination, a stale value is simply recomputed.
 */
final class WpCachePort implements CachePort {

	private const REVISIONS = 'mercatus_rev';

	private const REFRESH_LOCK_TTL = 30;

	/**
	 * Envelopes read or written this request, by group and revision, then key.
	 *
	 * @var array<string, array<string, array{v: mixed, s: int}>>
	 */
	private array $memory = array();

	/**
	 * Revisions read this request, by scope.
	 *
	 * @var array<string, int>
	 */
	private array $revisions = array();

	/**
	 * Creates the adapter.
	 *
	 * @param Clock            $clock        Current time.
	 * @param CoordinationPort $coordination Refresh locks for remember().
	 */
	public function __construct( private Clock $clock, private CoordinationPort $coordination ) {}

	public function get( string $group, string $key ): mixed {
		$envelope = $this->fetch( $group, $key );

		return null === $envelope ? null : $envelope['v'];
	}

	public function getMany( string $group, array $keys ): array {
		$bucket  = $this->bucket( $group );
		$found   = array();
		$missing = array();
		foreach ( $keys as $key ) {
			if ( isset( $this->memory[ $bucket ][ $key ] ) ) {
				$found[ $key ] = $this->memory[ $bucket ][ $key ]['v'];
			} else {
				$missing[ $this->storageKey( $bucket, $key ) ] = $key;
			}
		}
		if ( array() === $missing ) {
			return $found;
		}

		foreach ( wp_cache_get_multiple( array_keys( $missing ), $group ) as $storage_key => $stored ) {
			$key = $missing[ $storage_key ] ?? null;
			if ( null !== $key && SoftExpiry::isEnvelope( $stored ) ) {
				$this->memory[ $bucket ][ $key ] = $stored;
				$found[ $key ]                   = $stored['v'];
			}
		}

		return $found;
	}

	public function set( string $group, string $key, mixed $value, int $ttl ): void {
		$this->setMany( $group, array( $key => $value ), $ttl );
	}

	public function setMany( string $group, array $values, int $ttl ): void {
		if ( $ttl < 1 || in_array( null, $values, true ) ) {
			throw new InvalidArgumentException( 'Cache values are never null and TTLs are at least 1.' );
		}
		$bucket = $this->bucket( $group );
		$data   = array();
		foreach ( $values as $key => $value ) {
			$envelope                                   = SoftExpiry::plain( $value );
			$data[ $this->storageKey( $bucket, $key ) ] = $envelope;
			$this->memory[ $bucket ][ $key ]            = $envelope;
		}
		wp_cache_set_multiple( $data, $group, $ttl );
	}

	public function delete( string $group, string $key ): void {
		$bucket = $this->bucket( $group );
		unset( $this->memory[ $bucket ][ $key ] );
		wp_cache_delete( $this->storageKey( $bucket, $key ), $group );
	}

	public function remember( string $group, string $key, int $ttl, callable $compute ): mixed {
		if ( $ttl < 1 ) {
			throw new InvalidArgumentException( 'Cache TTLs are at least 1.' );
		}
		$now      = $this->clock->now();
		$envelope = $this->fetch( $group, $key );
		if ( null !== $envelope && SoftExpiry::isFresh( $envelope, $now ) ) {
			return $envelope['v'];
		}

		$lock   = self::refreshLock( $group, $key );
		$locked = false;
		if ( null !== $envelope && $this->coordination->isAvailable() ) {
			$locked = $this->coordination->acquireLock( $lock, self::REFRESH_LOCK_TTL );
			if ( ! $locked ) {
				return $envelope['v'];
			}
		}

		try {
			$value = $compute();
			if ( null === $value ) {
				throw new InvalidArgumentException( 'A remembered value is never null.' );
			}
			$bucket                          = $this->bucket( $group );
			$fresh                           = SoftExpiry::wrap( $value, $ttl, $now );
			$this->memory[ $bucket ][ $key ] = $fresh;
			wp_cache_set( $this->storageKey( $bucket, $key ), $fresh, $group, SoftExpiry::hardTtl( $ttl ) );

			return $value;
		} finally {
			if ( $locked ) {
				$this->coordination->releaseLock( $lock );
			}
		}
	}

	public function revision( string $scope ): int {
		if ( isset( $this->revisions[ $scope ] ) ) {
			return $this->revisions[ $scope ];
		}
		$revision = wp_cache_get( $scope, self::REVISIONS );
		if ( ! is_numeric( $revision ) ) {
			wp_cache_add( $scope, $this->seed(), self::REVISIONS );
			$revision = wp_cache_get( $scope, self::REVISIONS );
		}
		$this->revisions[ $scope ] = is_numeric( $revision ) ? (int) $revision : $this->seed();

		return $this->revisions[ $scope ];
	}

	public function bumpRevision( string $scope ): int {
		$current = $this->revision( $scope );
		$bumped  = wp_cache_incr( $scope, 1, self::REVISIONS );
		if ( ! is_int( $bumped ) ) {
			$bumped = max( $this->seed(), $current + 1 );
			wp_cache_set( $scope, $bumped, self::REVISIONS );
		}
		$this->revisions[ $scope ] = $bumped;

		return $bumped;
	}

	public function flushGroup( string $group ): void {
		$this->bumpRevision( 'group:' . $group );
	}

	/**
	 * Name of the CoordinationPort lock held while one caller refreshes a stale value.
	 *
	 * @param string $group Cache group.
	 * @param string $key   Key.
	 */
	public static function refreshLock( string $group, string $key ): string {
		return 'refresh:' . $group . ':' . $key;
	}

	/**
	 * Drops the per-request memory. Long-running processes, such as the outbox worker, call it between jobs
	 * so they see other processes' writes and revision bumps.
	 */
	public function forgetRequestMemory(): void {
		$this->memory    = array();
		$this->revisions = array();
	}

	/**
	 * An envelope from memory or the backend.
	 *
	 * @param string $group Cache group.
	 * @param string $key   Key.
	 * @return array{v: mixed, s: int}|null
	 */
	private function fetch( string $group, string $key ): ?array {
		$bucket = $this->bucket( $group );
		if ( isset( $this->memory[ $bucket ][ $key ] ) ) {
			return $this->memory[ $bucket ][ $key ];
		}
		$stored = wp_cache_get( $this->storageKey( $bucket, $key ), $group );
		if ( ! SoftExpiry::isEnvelope( $stored ) ) {
			return null;
		}
		$this->memory[ $bucket ][ $key ] = $stored;

		return $stored;
	}

	/**
	 * A group at its current revision.
	 *
	 * @param string $group Cache group.
	 */
	private function bucket( string $group ): string {
		return $group . ':' . $this->revision( 'group:' . $group );
	}

	/**
	 * The backend key: the group's revision, then the key.
	 *
	 * @param string $bucket Group at its revision.
	 * @param string $key    Key.
	 */
	private function storageKey( string $bucket, string $key ): string {
		return substr( $bucket, (int) strrpos( $bucket, ':' ) + 1 ) . ':' . $key;
	}

	/**
	 * A new revision pointer's starting value: the current time in milliseconds.
	 */
	private function seed(): int {
		return (int) $this->clock->now()->format( 'Uv' );
	}
}
