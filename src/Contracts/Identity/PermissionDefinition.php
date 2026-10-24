<?php
/**
 * A named permission (SEC-2, ARCH-8 point 3).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Contracts\Identity;

use InvalidArgumentException;

/**
 * Routes and menu items name the permission they need (IDN-4); managers hold the ones the shared set grants
 * (IDN-15), and staff the ones their owner grants (Pro).
 */
final readonly class PermissionDefinition {

	private const NAME = '/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/';

	/**
	 * Creates the definition.
	 *
	 * @param string $name  Dotted name, for example withdrawals.approve.
	 * @param string $label What it allows, for the settings screen.
	 * @throws InvalidArgumentException When the name is malformed.
	 */
	public function __construct( public string $name, public string $label ) {
		if ( 1 !== preg_match( self::NAME, $name ) ) {
			throw new InvalidArgumentException( 'A permission name is dotted lower-case words, for example withdrawals.approve.' );
		}
	}
}
