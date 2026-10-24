<?php
/**
 * CoordinationPort over WordPress's object cache (PLAT-6, KER-6).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Infrastructure\Cache;

use Mercatus\Core\Contracts\Cache\CoordinationPort;
use Mercatus\Core\Shared\Clock\Clock;

/**
 * Counters and locks through `wp_cache_add` and `wp_cache_incr`, which are atomic in Redis and Memcached.
 * Without a persistent object cache they would last only one request, so the port reports itself unavailable.
 * This adapter and WpCachePort are the only code that asks whether a persistent cache exists (PLAT-3).
 */
final class WpCoordinationPort implements CoordinationPort {

	private const COUNTERS = 'mercatus_ratelimits';

	private const LOCKS = 'mercatus_locks';

	private bool $available;

	/**
	 * Tokens of the locks this request holds, by key, so a request releases only its own lock.
	 *
	 * @var array<string, string>
	 */
	private array $tokens = array();

	/**
	 * Creates the adapter.
	 *
	 * @param Clock $clock Current time, for counter windows.
	 */
	public function __construct( private Clock $clock ) {
		$this->available = (bool) wp_using_ext_object_cache();
	}

	public function isAvailable(): bool {
		return $this->available;
	}

	public function increment( string $key, int $window ): ?int {
		if ( ! $this->available ) {
			return null;
		}
		$window = max( 1, $window );
		$slot   = $key . ':' . intdiv( $this->clock->now()->getTimestamp(), $window );
		wp_cache_add( $slot, 0, self::COUNTERS, $window );
		$count = wp_cache_incr( $slot, 1, self::COUNTERS );

		return is_int( $count ) ? $count : null;
	}

	public function acquireLock( string $key, int $ttl ): bool {
		if ( ! $this->available ) {
			return false;
		}
		$token = wp_generate_uuid4();
		if ( ! wp_cache_add( $key, $token, self::LOCKS, max( 1, $ttl ) ) ) {
			return false;
		}
		$this->tokens[ $key ] = $token;

		return true;
	}

	public function releaseLock( string $key ): void {
		if ( ! isset( $this->tokens[ $key ] ) ) {
			return;
		}
		if ( wp_cache_get( $key, self::LOCKS ) === $this->tokens[ $key ] ) {
			wp_cache_delete( $key, self::LOCKS );
		}
		unset( $this->tokens[ $key ] );
	}
}
