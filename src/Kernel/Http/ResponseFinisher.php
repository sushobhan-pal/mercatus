<?php
/**
 * Finishes every Mercatus response (API-1, PLAT-3, PLAT-9, IDN-6, IDN-8).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Kernel\Http;

use Mercatus\Core\Contracts\Http\Access;
use Mercatus\Core\Shared\Actor\ExecutionContext;
use Mercatus\Core\Shared\Http\ETag;
use WP_Error;
use WP_HTTP_Response;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Hooked to `rest_request_after_callbacks`, which runs for every dispatch, internal ones and refusals
 * included. Errors become the API-1 envelope with the request ID; every response carries `X-Request-Id`; a
 * GET whose ETag the client already has becomes a 304; and Cache-Control follows the access level unless the
 * endpoint set it.
 */
final class ResponseFinisher {

	/**
	 * Creates the finisher.
	 *
	 * @param ExecutionContext $context Request ID.
	 */
	public function __construct( private ExecutionContext $context ) {}

	/**
	 * Finishes a response from a Mercatus route; leaves others untouched.
	 *
	 * @param mixed           $response Callback result.
	 * @param array<mixed>    $handler  Matched endpoint.
	 * @param WP_REST_Request $request  Request.
	 * @return mixed
	 */
	public function finish( mixed $response, array $handler, WP_REST_Request $request ): mixed {
		if ( ! str_starts_with( $request->get_route(), '/' . WordPressRouteRegistrar::NAMESPACE . '/' ) ) {
			return $response;
		}
		$response = $response instanceof WP_Error ? $this->envelope( $response ) : $response;
		if ( ! $response instanceof WP_HTTP_Response ) {
			return $response;
		}
		$meta    = RouteMeta::of( $handler );
		$headers = $response->get_headers();
		$etag    = is_string( $headers['ETag'] ?? null ) ? $headers['ETag'] : null;
		$get     = in_array( $request->get_method(), array( 'GET', 'HEAD' ), true );
		if ( $get && 200 === $response->get_status() && null !== $etag && ETag::matches( $request->get_header( 'if_none_match' ), $etag ) ) {
			$response = new WP_REST_Response( null, 304, array_intersect_key( $headers, array_flip( array( 'ETag', 'Cache-Control' ) ) ) );
		}
		if ( ! isset( $response->get_headers()['Cache-Control'] ) ) {
			$response->header( 'Cache-Control', $this->cacheControl( $get, $response->get_status(), $meta?->access ) );
		}
		$response->header( 'X-Request-Id', $this->context->requestId() );

		return $response;
	}

	/**
	 * The API-1 error envelope.
	 *
	 * @param WP_Error $error Error.
	 */
	private function envelope( WP_Error $error ): WP_REST_Response {
		$data    = $error->get_error_data();
		$details = is_array( $data ) ? $data : array();
		$status  = is_int( $details['status'] ?? null ) ? $details['status'] : 500;
		unset( $details['status'] );

		return new WP_REST_Response(
			array(
				'code'       => (string) $error->get_error_code(),
				'message'    => $error->get_error_message(),
				'details'    => (object) $details,
				'request_id' => $this->context->requestId(),
			),
			$status
		);
	}

	/**
	 * Cache-Control by access level (PLAT-3 L3).
	 *
	 * @param bool        $get    Whether it's a safe read.
	 * @param int         $status Response status.
	 * @param Access|null $access The route's access level.
	 */
	private function cacheControl( bool $get, int $status, ?Access $access ): string {
		if ( ! $get || $status >= 400 ) {
			return 'no-store';
		}

		return Access::Open === $access ? 'public, max-age=60, stale-while-revalidate=300' : 'private, no-cache';
	}
}
