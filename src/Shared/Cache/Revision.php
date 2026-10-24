<?php
/**
 * A revision marker in a cache key (PLAT-5).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Shared\Cache;

use InvalidArgumentException;

/**
 * Stands for a scope's current revision, for example Revision::of( 'product:42' ).
 */
final readonly class Revision {

	/**
	 * Creates the marker.
	 *
	 * @param string $scope Revision scope, such as product:42.
	 * @throws InvalidArgumentException When the scope is empty.
	 */
	private function __construct( public string $scope ) {
		if ( '' === $scope ) {
			throw new InvalidArgumentException( 'A revision scope cannot be empty.' );
		}
	}

	/**
	 * A marker for a scope.
	 *
	 * @param string $scope Revision scope.
	 */
	public static function of( string $scope ): self {
		return new self( $scope );
	}
}
