<?php
/**
 * The kernel's access gate until core.identity exists (KER-11).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Kernel\Http;

use Mercatus\Core\Contracts\Http\Access;
use WP_Error;
use WP_REST_Request;

/**
 * Public routes are open; every other route is refused: 401 when nobody is signed in, 403 otherwise, even for
 * administrators, until `core.identity` plugs in its policies.
 */
final class FailClosedAccessGate implements AccessGate {

	public function check( Access $access, WP_REST_Request $request ): true|WP_Error {
		if ( Access::Open === $access ) {
			return true;
		}
		if ( 0 === get_current_user_id() ) {
			return new WP_Error( 'mercatus_unauthenticated', __( 'Sign in to continue.', 'mercatus' ), array( 'status' => 401 ) );
		}

		return new WP_Error( 'mercatus_forbidden', __( 'You are not allowed to do this.', 'mercatus' ), array( 'status' => 403 ) );
	}
}
