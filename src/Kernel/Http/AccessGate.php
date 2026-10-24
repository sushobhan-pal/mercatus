<?php
/**
 * Decides whether a request may call a route (SEC-2, KER-11).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Kernel\Http;

use Mercatus\Core\Contracts\Http\Access;
use WP_Error;
use WP_REST_Request;

/**
 * Every route's permission callback. `core.identity` replaces the kernel's fail-closed gate with its policy
 * pipeline.
 */
interface AccessGate {

	/**
	 * True when the request may proceed, otherwise an error with status 401 or 403.
	 *
	 * @param Access          $access     The route's access level.
	 * @param string|null     $permission The named permission the route needs, if any.
	 * @param WP_REST_Request $request    Request.
	 */
	public function check( Access $access, ?string $permission, WP_REST_Request $request ): true|WP_Error;

	/**
	 * Whether a named permission exists, so the registrar can refuse routes that name an unknown one.
	 *
	 * @param string $permission Permission name.
	 */
	public function knows( string $permission ): bool;
}
