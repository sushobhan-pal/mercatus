<?php
/**
 * Who did something (SEC-1).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Shared\Actor;

use InvalidArgumentException;

/**
 * An actor's type and ID as events and the audit log record them. `core.identity` refines the types into
 * SEC-1's actors; until then WordPress users are "user".
 */
final readonly class ActorRef {

	/**
	 * Creates the reference.
	 *
	 * @param string $type Actor type, lower case, for example user, system or anonymous.
	 * @param int    $id   Actor ID; 0 for system and anonymous.
	 * @throws InvalidArgumentException When the type or ID is malformed.
	 */
	public function __construct( public string $type, public int $id ) {
		if ( 1 !== preg_match( '/^[a-z][a-z_]{0,31}$/', $type ) || $id < 0 ) {
			throw new InvalidArgumentException( 'An actor has a lower-case type and a non-negative ID.' );
		}
	}

	/**
	 * The system actor of a worker or CLI command (SEC-1).
	 */
	public static function system(): self {
		return new self( 'system', 0 );
	}

	/**
	 * Nobody signed in.
	 */
	public static function anonymous(): self {
		return new self( 'anonymous', 0 );
	}

	/**
	 * Whether this is the system actor.
	 */
	public function isSystem(): bool {
		return 'system' === $this->type;
	}
}
