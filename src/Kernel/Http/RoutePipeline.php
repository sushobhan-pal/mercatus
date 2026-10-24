<?php
/**
 * What every Mercatus endpoint runs through (SEC-2, API-1, IDN-5, IDN-6, IDN-8).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Kernel\Http;

use Mercatus\Core\Shared\Errors\DomainError;
use Mercatus\Core\Shared\Http\ApiError;
use Throwable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The registrar wraps each endpoint's permission callback with permit() and its callback with run(), so no
 * route can skip a step.
 */
final class RoutePipeline {

	/**
	 * Creates the pipeline.
	 *
	 * @param AccessGate $gate Access checks.
	 */
	public function __construct( private AccessGate $gate ) {}

	/**
	 * Whether the request may reach the endpoint.
	 *
	 * @param RouteMeta       $meta    The endpoint's metadata.
	 * @param WP_REST_Request $request Request.
	 */
	public function permit( RouteMeta $meta, WP_REST_Request $request ): true|WP_Error {
		return $this->gate->check( $meta->access, $meta->permission, $request );
	}

	/**
	 * Runs the endpoint. A PATCH or PUT needs If-Match (PLAT-9); a domain error that escapes becomes a 400, and
	 * anything unexpected a 500 without details (API-1).
	 *
	 * @param RouteMeta       $meta     The endpoint's metadata.
	 * @param callable        $callback The endpoint's callback.
	 * @param WP_REST_Request $request  Request.
	 */
	public function run( RouteMeta $meta, callable $callback, WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( in_array( $request->get_method(), array( 'PATCH', 'PUT' ), true ) && null === $request->get_header( 'if_match' ) ) {
			return new WP_Error( 'mercatus_if_match_required', __( 'Send the If-Match header with the version you are changing.', 'mercatus' ), array( 'status' => 428 ) );
		}

		try {
			$result = $callback( $request );

			return $result instanceof WP_Error ? $result : rest_ensure_response( $result );
		} catch ( DomainError $error ) {
			return ApiError::from( $error );
		} catch ( Throwable $error ) {
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->error( $error->getMessage(), array( 'source' => 'mercatus' ) );
			}

			return new WP_Error( 'mercatus_internal_error', __( 'Something went wrong. Try again later.', 'mercatus' ), array( 'status' => 500 ) );
		}
	}
}
