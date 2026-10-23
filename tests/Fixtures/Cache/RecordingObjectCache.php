<?php
/**
 * Records calls to WordPress's object cache.
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Fixtures\Cache;

/**
 * Wraps the global $wp_object_cache and records each method call with its group, so tests can count backend
 * round trips. Install it only around the code under test: by-reference arguments such as $found don't pass
 * through a proxy.
 */
final class RecordingObjectCache {

	/**
	 * Calls as [method, group].
	 *
	 * @var list<array{string, string}>
	 */
	public array $calls = array();

	/**
	 * Wraps a cache object.
	 *
	 * @param object $inner The real object cache.
	 */
	public function __construct( public readonly object $inner ) {}

	/**
	 * Records and forwards a method call.
	 *
	 * @param string       $name      Method.
	 * @param array<mixed> $arguments Arguments.
	 */
	public function __call( string $name, array $arguments ): mixed {
		$group         = $arguments[1] ?? '';
		$this->calls[] = array( $name, is_string( $group ) ? $group : '' );

		return $this->inner->{$name}( ...$arguments );
	}

	/**
	 * Forwards a property read.
	 *
	 * @param string $name Property.
	 */
	public function __get( string $name ): mixed {
		return $this->inner->{$name};
	}

	/**
	 * Forwards a property write.
	 *
	 * @param string $name  Property.
	 * @param mixed  $value Value.
	 */
	public function __set( string $name, mixed $value ): void {
		$this->inner->{$name} = $value;
	}

	/**
	 * Forwards isset().
	 *
	 * @param string $name Property.
	 */
	public function __isset( string $name ): bool {
		return isset( $this->inner->{$name} );
	}

	/**
	 * How many times a method was called for a group.
	 *
	 * @param string $method Method.
	 * @param string $group  Cache group.
	 */
	public function count( string $method, string $group ): int {
		return count( array_filter( $this->calls, static fn( array $call ): bool => array( $method, $group ) === $call ) );
	}
}
