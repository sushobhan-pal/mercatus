<?php
/**
 * Who a request counts against (IDN-9, IDN-10).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Identity\Infrastructure;

use Mercatus\Core\Contracts\Identity\ActorIdentity;
use Mercatus\Core\Contracts\Identity\CurrentActor;

/**
 * The API client, the signed-in user, or the visitor's IP address. Rate limits count against it, and
 * idempotency keys are scoped to it.
 */
final class RequestSubject {

	/**
	 * Creates the subject.
	 *
	 * @param CurrentActor  $actors  The current actor.
	 * @param ClientAddress $address The visitor's address.
	 */
	public function __construct( private CurrentActor $actors, private ClientAddress $address ) {}

	/**
	 * The current identity.
	 */
	public function identity(): ActorIdentity {
		return $this->actors->identity();
	}

	/**
	 * client, user or visitor.
	 */
	public function kind(): string {
		$identity = $this->identity();
		if ( null !== $identity->apiClientId ) {
			return 'client';
		}

		return $identity->isSignedIn() ? 'user' : 'visitor';
	}

	/**
	 * client:ID, user:ID or ip:ADDRESS.
	 */
	public function key(): string {
		$identity = $this->identity();

		return match ( $this->kind() ) {
			'client' => 'client:' . $identity->apiClientId,
			'user'   => 'user:' . $identity->userId,
			default  => 'ip:' . $this->address->current(),
		};
	}
}
