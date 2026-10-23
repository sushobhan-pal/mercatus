<?php
/**
 * In-memory CoordinationPort for tests.
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Contract\Fakes;

use Mercatus\Core\Contracts\Cache\CoordinationPort;

/**
 * Counters and locks for one process. Built unavailable, it behaves like a site without a persistent cache.
 */
final class InMemoryCoordinationPort implements CoordinationPort {

	/**
	 * Counts by key and window.
	 *
	 * @var array<string, int>
	 */
	private array $counters = array();

	/**
	 * Lock expiry times by key.
	 *
	 * @var array<string, int>
	 */
	private array $locks = array();

	/**
	 * Creates the port.
	 *
	 * @param bool $available Whether counters and locks work.
	 */
	public function __construct( private bool $available = true ) {}

	public function isAvailable(): bool {
		return $this->available;
	}

	public function increment( string $key, int $window ): ?int {
		if ( ! $this->available ) {
			return null;
		}
		$slot                    = $key . ':' . intdiv( time(), max( 1, $window ) );
		$this->counters[ $slot ] = ( $this->counters[ $slot ] ?? 0 ) + 1;

		return $this->counters[ $slot ];
	}

	public function acquireLock( string $key, int $ttl ): bool {
		if ( ! $this->available || ( $this->locks[ $key ] ?? 0 ) > time() ) {
			return false;
		}
		$this->locks[ $key ] = time() + max( 1, $ttl );

		return true;
	}

	public function releaseLock( string $key ): void {
		unset( $this->locks[ $key ] );
	}
}
