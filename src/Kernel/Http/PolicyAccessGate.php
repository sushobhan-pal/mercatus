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
use Mercatus\Core\Contracts\Identity\AccessDecision;
use Mercatus\Core\Identity\Domain\PolicyEngine;
use Mercatus\Core\Identity\Infrastructure\ApiClientAuthentication;
use WP_Error;
use WP_REST_Request;

/**
 * Replaces the kernel's interim fail-closed gate (KER-11): the current identity against the route's access
 * level and named permission, after the request's application password and its client's scopes are
 * checked (SEC-2 steps 1–4). Resource policies run in each module's application layer.
 */
final class PolicyAccessGate implements AccessGate {

	/**
	 * Creates the gate.
	 *
	 * @param CurrentActor            $actors      The current actor.
	 * @param PermissionCatalog       $permissions Known permissions.
	 * @param ApiClientAuthentication $clients     The request's application password, if any.
	 */
	public function __construct( private CurrentActor $actors, private PermissionCatalog $permissions, private ApiClientAuthentication $clients ) {}

	public function check( Access $access, ?string $permission, WP_REST_Request $request ): true|WP_Error {
		$problem = $this->clients->problem();
		if ( null !== $problem ) {
			$denied = 'mercatus_api_client_ip_denied' === $problem;

			return new WP_Error( $problem, $denied ? __( 'This API client may not be used from this address.', 'mercatus' ) : __( 'This application password is not accepted.', 'mercatus' ), array( 'status' => $denied ? 403 : 401 ) );
		}
		$identity = $this->actors->identity();
		$write    = ! in_array( $request->get_method(), array( 'GET', 'HEAD' ), true );
		if ( AccessDecision::Allowed !== PolicyEngine::scope( $identity, $permission, $write ) ) {
			return new WP_Error( 'mercatus_api_client_scope', __( "This API client's scopes don't allow this.", 'mercatus' ), array( 'status' => 403 ) );
		}

		return match ( PolicyEngine::access( $identity, $access, $permission ) ) {
			AccessDecision::Allowed         => true,
			AccessDecision::Unauthenticated => new WP_Error( 'mercatus_unauthenticated', __( 'Sign in to continue.', 'mercatus' ), array( 'status' => 401 ) ),
			default                         => new WP_Error( 'mercatus_forbidden', __( 'You are not allowed to do this.', 'mercatus' ), array( 'status' => 403 ) ),
		};
	}

	public function knows( string $permission ): bool {
		return $this->permissions->knows( $permission );
	}
}
