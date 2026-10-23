<?php
/**
 * In-memory CachePort for tests.
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Contract\Fakes;

use InvalidArgumentException;
use Mercatus\Core\Contracts\Cache\CachePort;

/**
 * Keeps values in an array for the life of the object. TTLs are validated but never expire anything.
 */
final class InMemoryCachePort implements CachePort {

	/**
	 * Values by versioned group, then key.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $values = array();

	/**
	 * Revisions by scope.
	 *
	 * @var array<string, int>
	 */
	private array $revisions = array();

	public function get( string $group, string $key ): mixed {
		return $this->values[ $this->bucket( $group ) ][ $key ] ?? null;
	}

	public function getMany( string $group, array $keys ): array {
		return array_intersect_key( $this->values[ $this->bucket( $group ) ] ?? array(), array_flip( $keys ) );
	}

	public function set( string $group, string $key, mixed $value, int $ttl ): void {
		$this->setMany( $group, array( $key => $value ), $ttl );
	}

	public function setMany( string $group, array $values, int $ttl ): void {
		if ( $ttl < 1 || in_array( null, $values, true ) ) {
			throw new InvalidArgumentException( 'Cache values are never null and TTLs are at least 1.' );
		}
		$bucket                  = $this->bucket( $group );
		$this->values[ $bucket ] = array_merge( $this->values[ $bucket ] ?? array(), $values );
	}

	public function delete( string $group, string $key ): void {
		unset( $this->values[ $this->bucket( $group ) ][ $key ] );
	}

	public function remember( string $group, string $key, int $ttl, callable $compute ): mixed {
		$value = $this->get( $group, $key );
		if ( null !== $value ) {
			return $value;
		}
		$value = $compute();
		if ( null === $value ) {
			throw new InvalidArgumentException( 'A remembered value is never null.' );
		}
		$this->set( $group, $key, $value, $ttl );

		return $value;
	}

	public function revision( string $scope ): int {
		$this->revisions[ $scope ] ??= time();

		return $this->revisions[ $scope ];
	}

	public function bumpRevision( string $scope ): int {
		$this->revisions[ $scope ] = max( $this->revision( $scope ) + 1, time() );

		return $this->revisions[ $scope ];
	}

	public function flushGroup( string $group ): void {
		$this->bumpRevision( 'group:' . $group );
	}

	/**
	 * Storage bucket for a group at its current revision (PLAT-5).
	 *
	 * @param string $group Cache group.
	 */
	private function bucket( string $group ): string {
		return $group . ':' . $this->revision( 'group:' . $group );
	}
}
