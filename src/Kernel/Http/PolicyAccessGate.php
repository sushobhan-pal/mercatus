<?php
/**
 * Route access through the policy engine (SEC-2, IDN-5).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Kernel\Http;

use Mercatus\Core\Contracts\Http\Access;
use Mercatus\Core\Contracts\Identity\CurrentActor;
use Mercatus\Core\Identity\Application\PermissionCatalog;
use Mercatus\Core\Identity\Domain\AccessDecision;
use Mercatus\Core\Identity\Domain\PolicyEngine;
use WP_Error;
use WP_REST_Request;

/**
 * Replaces the kernel's interim fail-closed gate (KER-11): the current identity against the route's access
 * level and named permission. API-client scopes join with ticket 11; resource policies run in each module's
 * application layer.
 */
final class PolicyAccessGate implements AccessGate {

	/**
	 * Creates the gate.
	 *
	 * @param CurrentActor      $actors      The current actor.
	 * @param PermissionCatalog $permissions Known permissions.
	 */
	public function __construct( private CurrentActor $actors, private PermissionCatalog $permissions ) {}

	public function check( Access $access, ?string $permission, WP_REST_Request $request ): true|WP_Error {
		return match ( PolicyEngine::access( $this->actors->identity(), $access, $permission ) ) {
			AccessDecision::Allowed         => true,
			AccessDecision::Unauthenticated => new WP_Error( 'mercatus_unauthenticated', __( 'Sign in to continue.', 'mercatus' ), array( 'status' => 401 ) ),
			default                         => new WP_Error( 'mercatus_forbidden', __( 'You are not allowed to do this.', 'mercatus' ), array( 'status' => 403 ) ),
		};
	}

	public function knows( string $permission ): bool {
		return $this->permissions->knows( $permission );
	}
}
