<?php
/**
 * What every Mercatus endpoint runs through (SEC-2, API-1, IDN-5, IDN-6, IDN-8).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Kernel\Http;

use Mercatus\Core\Identity\Application\RateLimitDecision;
use Mercatus\Core\Identity\Application\RateLimiter;
use Mercatus\Core\Identity\Infrastructure\IdempotentRequests;
use Mercatus\Core\Identity\Infrastructure\RequestSubject;
use Mercatus\Core\Shared\Errors\DomainError;
use Mercatus\Core\Shared\Http\ApiError;
use Throwable;
use WeakMap;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The registrar wraps each endpoint's permission callback with permit() and its callback with run(), so no
 * route can skip a step. Rate limiting comes first, so floods are counted before any other work.
 */
final class RoutePipeline {

	/**
	 * Each request's rate-limit decision, for the response headers.
	 *
	 * @var WeakMap<WP_REST_Request, RateLimitDecision>
	 */
	private WeakMap $limits;

	/**
	 * Creates the pipeline.
	 *
	 * @param AccessGate         $gate        Access checks.
	 * @param RateLimiter        $limiter     Rate limits.
	 * @param RequestSubject     $subject     Who a request counts against.
	 * @param IdempotentRequests $idempotency Idempotency keys.
	 */
	public function __construct( private AccessGate $gate, private RateLimiter $limiter, private RequestSubject $subject, private IdempotentRequests $idempotency ) {
		$this->limits = new WeakMap();
	}

	/**
	 * Whether the request may reach the endpoint.
	 *
	 * @param RouteMeta       $meta    The endpoint's metadata.
	 * @param WP_REST_Request $request Request.
	 */
	public function permit( RouteMeta $meta, WP_REST_Request $request ): true|WP_Error {
		$decision                 = $this->subject->identity()->isAdministrator()
			? RateLimitDecision::unlimited()
			: $this->limiter->hit( $this->subject->key(), $this->subject->kind(), ! in_array( $request->get_method(), array( 'GET', 'HEAD' ), true ), $request->get_method() . ' ' . $request->get_route(), $meta->rateLimit );
		$this->limits[ $request ] = $decision;
		if ( ! $decision->allowed ) {
			return new WP_Error( 'mercatus_rate_limited', __( 'Too many requests. Try again shortly.', 'mercatus' ), array( 'status' => 429 ) );
		}

		return $this->gate->check( $meta->access, $meta->permission, $request );
	}

	/**
	 * The request's rate-limit decision, once permit() ran.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function rateLimit( WP_REST_Request $request ): ?RateLimitDecision {
		return $this->limits[ $request ] ?? null;
	}

	/**
	 * Runs the endpoint. A PATCH or PUT needs If-Match (PLAT-9), a POST runs under its Idempotency-Key
	 * (PLAT-7), a domain error that escapes becomes a 400, and anything unexpected a 500 without details (API-1).
	 *
	 * @param RouteMeta       $meta     The endpoint's metadata.
	 * @param callable        $callback The endpoint's callback.
	 * @param WP_REST_Request $request  Request.
	 */
	public function run( RouteMeta $meta, callable $callback, WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( in_array( $request->get_method(), array( 'PATCH', 'PUT' ), true ) && null === $request->get_header( 'if_match' ) ) {
			return new WP_Error( 'mercatus_if_match_required', __( 'Send the If-Match header with the version you are changing.', 'mercatus' ), array( 'status' => 428 ) );
		}

		if ( 'POST' === $request->get_method() ) {
			return $this->idempotency->handle( $request, $meta->idempotent, fn(): WP_REST_Response|WP_Error => $this->call( $callback, $request ) );
		}

		return $this->call( $callback, $request );
	}

	/**
	 * Calls the endpoint, turning anything it throws into an error response.
	 *
	 * @param callable        $callback The endpoint's callback.
	 * @param WP_REST_Request $request  Request.
	 */
	private function call( callable $callback, WP_REST_Request $request ): WP_REST_Response|WP_Error {
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
