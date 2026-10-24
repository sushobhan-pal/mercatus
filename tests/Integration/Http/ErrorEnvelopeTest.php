<?php
/**
 * Every Mercatus response is finished the same way (API-1, PLAT-3, PLAT-9, IDN-6, IDN-8).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Integration\Http;

use Mercatus\Core\Contracts\Http\Access;
use Mercatus\Core\Kernel\Http\RouteMeta;
use Mercatus\Core\Kernel\Http\RoutePipeline;
use Mercatus\Core\Kernel\Kernel;
use Mercatus\Core\Shared\Errors\DomainError;
use Mercatus\Core\Tests\Integration\Identity\IdentityTestActors;
use RuntimeException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * Errors, from refusals, validation, domain errors or failures, share the API-1 envelope with the request ID;
 * cache headers follow the access level; a known ETag gets a 304.
 */
final class ErrorEnvelopeTest extends WP_UnitTestCase {

	use IdentityTestActors;

	public function testARefusalUsesTheEnvelope(): void {
		wp_set_current_user( 0 );
		$response = rest_do_request( new WP_REST_Request( 'GET', '/mercatus/v1/health' ) );
		$data     = $response->get_data();

		$this->assertSame( 401, $response->get_status() );
		$this->assertIsArray( $data );
		$this->assertSame( array( 'code', 'message', 'details', 'request_id' ), array_keys( $data ) );
		$this->assertSame( 'mercatus_unauthenticated', $data['code'] );
		$this->assertMatchesRegularExpression( '/^req_[0-9a-f]{32}$/', $data['request_id'] );
		$this->assertSame( $data['request_id'], $response->get_headers()['X-Request-Id'] ?? null );
		$this->assertSame( 'no-store', $response->get_headers()['Cache-Control'] ?? null );
	}

	public function testAValidationErrorUsesTheEnvelope(): void {
		wp_set_current_user( $this->userAs( 'administrator' ) );
		$request = new WP_REST_Request( 'POST', '/mercatus/v1/system/cache/flush-group' );
		$request->set_param( 'group', 'not a group' );

		$data = $this->data( rest_do_request( $request ), 400 );

		$this->assertSame( 'rest_invalid_param', $data['code'] );
		$this->assertArrayHasKey( 'params', (array) $data['details'] );
	}

	public function testADomainErrorUsesItsModulesStatus(): void {
		wp_set_current_user( $this->userAs( 'administrator' ) );

		$data = $this->data( rest_do_request( new WP_REST_Request( 'POST', '/mercatus/v1/modules/core.kernel/deactivate' ) ), 409 );

		$this->assertSame( 'mercatus_module_not_optional', $data['code'] );
	}

	public function testCacheHeadersFollowTheAccessLevel(): void {
		wp_set_current_user( $this->userAs( 'customer' ) );

		$this->assertSame( 'public, max-age=60, stale-while-revalidate=300', rest_do_request( new WP_REST_Request( 'GET', '/mercatus/v1/features' ) )->get_headers()['Cache-Control'] ?? null );
		$this->assertSame( 'private, no-cache', rest_do_request( new WP_REST_Request( 'GET', '/mercatus/v1/me' ) )->get_headers()['Cache-Control'] ?? null );
	}

	public function testAKnownETagGetsANotModified(): void {
		wp_set_current_user( $this->userAs( 'customer' ) );
		$etag = rest_do_request( new WP_REST_Request( 'GET', '/mercatus/v1/me' ) )->get_headers()['ETag'] ?? null;
		$this->assertIsString( $etag );

		$request = new WP_REST_Request( 'GET', '/mercatus/v1/me' );
		$request->set_header( 'If-None-Match', $etag );
		$response = rest_do_request( $request );

		$this->assertSame( 304, $response->get_status() );
		$this->assertNull( $response->get_data() );
		$this->assertSame( $etag, $response->get_headers()['ETag'] ?? null );
	}

	public function testAChangeWithoutIfMatchIsRefused(): void {
		$result = $this->pipeline()->run( new RouteMeta( Access::Admin ), static fn(): array => array(), new WP_REST_Request( 'PATCH', '/mercatus/v1/probe' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( array( 'mercatus_if_match_required', 428 ), array( $result->get_error_code(), $result->get_error_data()['status'] ?? null ) );
	}

	public function testAnEscapedDomainErrorIsA400AndAFailureA500WithoutDetails(): void {
		$domain  = $this->pipeline()->run( new RouteMeta( Access::Admin ), static fn() => throw DomainError::of( 'mercatus_probe_refused' ), new WP_REST_Request( 'POST', '/mercatus/v1/probe' ) );
		$failure = $this->pipeline()->run( new RouteMeta( Access::Admin ), static fn() => throw new RuntimeException( 'secret path /var/www' ), new WP_REST_Request( 'POST', '/mercatus/v1/probe' ) );

		$this->assertInstanceOf( WP_Error::class, $domain );
		$this->assertSame( array( 'mercatus_probe_refused', 400 ), array( $domain->get_error_code(), $domain->get_error_data()['status'] ?? null ) );
		$this->assertInstanceOf( WP_Error::class, $failure );
		$this->assertSame( array( 'mercatus_internal_error', 500 ), array( $failure->get_error_code(), $failure->get_error_data()['status'] ?? null ) );
		$this->assertStringNotContainsString( 'secret', $failure->get_error_message() );
	}

	/**
	 * A response's envelope, after checking its status.
	 *
	 * @param WP_REST_Response $response Response.
	 * @param int              $status   Expected status.
	 * @return array<string, mixed>
	 */
	private function data( WP_REST_Response $response, int $status ): array {
		$this->assertSame( $status, $response->get_status() );
		$data = $response->get_data();
		$this->assertIsArray( $data );

		return $data;
	}

	/**
	 * The site kernel's pipeline.
	 */
	private function pipeline(): RoutePipeline {
		$kernel = Kernel::instance();
		$this->assertNotNull( $kernel );

		return $kernel->container()->get( RoutePipeline::class );
	}
}
