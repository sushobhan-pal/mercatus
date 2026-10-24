<?php
/**
 * A POST runs at most once per Idempotency-Key (TEST-3 row 4, PLAT-7, G-SEC-3, TM-17).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Integration\Identity;

use Mercatus\Core\Identity\Infrastructure\IdempotencyStore;
use Mercatus\Core\Identity\Infrastructure\IdempotentRequests;
use Mercatus\Core\Shared\Clock\Clock;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * The row is written before the endpoint runs, so a repeat can't run it again: a finished request replays,
 * a running one gets 409, another payload 422. A failure releases the key; expired rows are purged.
 */
final class IdempotencyStoreIntegrationTest extends WP_UnitTestCase {

	use IdentityTestActors;

	private int $runs = 0;

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( $this->userAs( 'customer' ) );
	}

	public function testAFinishedRequestReplaysWithoutRunningAgain(): void {
		$first  = $this->send( 'key-1', array( 'amount' => 10 ) );
		$repeat = $this->send( 'key-1', array( 'amount' => 10 ) );

		$this->assertSame( 1, $this->runs );
		$this->assertInstanceOf( WP_REST_Response::class, $repeat );
		$this->assertInstanceOf( WP_REST_Response::class, $first );
		$this->assertSame( array( 201, $first->get_data() ), array( $repeat->get_status(), $repeat->get_data() ) );
		$this->assertSame( 'true', $repeat->get_headers()['Idempotent-Replayed'] ?? null );
	}

	public function testTheSamePayloadInAnotherKeyOrderStillReplays(): void {
		$this->send(
			'key-2',
			array(
				'a' => 1,
				'b' => 2,
			)
		);
		$this->send(
			'key-2',
			array(
				'b' => 2,
				'a' => 1,
			)
		);

		$this->assertSame( 1, $this->runs );
	}

	public function testAnotherPayloadUnderTheSameKeyIsRefused(): void {
		$this->send( 'key-3', array( 'amount' => 10 ) );

		$this->assertError( 'mercatus_idempotency_mismatch', 422, $this->send( 'key-3', array( 'amount' => 99 ) ) );
		$this->assertSame( 1, $this->runs );
	}

	public function testARequestStillRunningIsRefused(): void {
		$this->assertError(
			'mercatus_idempotency_in_progress',
			409,
			$this->send(
				'key-4',
				array(),
				function (): WP_REST_Response|WP_Error {
					return $this->send( 'key-4', array() );
				}
			)
		);
	}

	public function testADomainErrorIsStoredButAFailureReleasesTheKey(): void {
		$refusal = static fn(): WP_Error => new WP_Error( 'mercatus_probe_refused', 'No.', array( 'status' => 409 ) );
		$failure = static fn(): WP_Error => new WP_Error( 'mercatus_internal_error', 'Oops.', array( 'status' => 500 ) );

		$this->send( 'key-5', array(), $refusal );
		$this->assertError( 'mercatus_probe_refused', 409, $this->send( 'key-5', array() ) );
		$this->assertSame( 0, $this->runs, 'The stored refusal replays.' );

		$this->send( 'key-6', array(), $failure );
		$this->assertInstanceOf( WP_REST_Response::class, $this->send( 'key-6', array() ) );
		$this->assertSame( 1, $this->runs, 'After a failure the retry runs.' );
	}

	public function testKeysBelongToTheirActor(): void {
		$this->send( 'key-7', array() );
		wp_set_current_user( $this->userAs( 'customer' ) );
		$this->send( 'key-7', array() );

		$this->assertSame( 2, $this->runs );
	}

	public function testARequiredKeyMustBeSentAndWellFormed(): void {
		$request = new WP_REST_Request( 'POST', '/mercatus/v1/probe' );
		$this->assertError( 'mercatus_idempotency_key_required', 400, $this->service()->handle( $request, true, fn() => $this->endpoint() ) );

		$this->assertError( 'mercatus_idempotency_key_invalid', 400, $this->send( "with spaces\n", array() ) );
		$this->assertSame( 0, $this->runs );
	}

	public function testPurgesOnlyExpiredRows(): void {
		$this->send( 'key-8', array() );
		$store = self::kernel()->container()->get( IdempotencyStore::class );
		$now   = self::kernel()->container()->get( Clock::class )->now();

		$this->assertSame( 0, $store->purge( $now ) );
		$this->assertSame( 1, $store->purge( $now->modify( '+25 hours' ) ) );
	}

	/**
	 * Sends a POST with a key and JSON body through the service.
	 *
	 * @param string                                       $key  Idempotency-Key.
	 * @param array<string, mixed>                         $body JSON body.
	 * @param (callable(): (WP_REST_Response|WP_Error))|null $next The endpoint; by default one that counts runs.
	 */
	private function send( string $key, array $body, ?callable $next = null ): WP_REST_Response|WP_Error {
		$request = new WP_REST_Request( 'POST', '/mercatus/v1/probe' );
		$request->set_header( 'Idempotency-Key', $key );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $body ) );

		return $this->service()->handle( $request, false, null === $next ? fn() => $this->endpoint() : \Closure::fromCallable( $next ) );
	}

	/**
	 * The default endpoint: counts its runs and creates something.
	 */
	private function endpoint(): WP_REST_Response {
		++$this->runs;

		return new WP_REST_Response( array( 'id' => $this->runs ), 201 );
	}

	/**
	 * Asserts an error's code and status.
	 *
	 * @param string                    $code     Code.
	 * @param int                       $status   Status.
	 * @param WP_REST_Response|WP_Error $response Response.
	 */
	private function assertError( string $code, int $status, WP_REST_Response|WP_Error $response ): void {
		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( array( $code, $status ), array( $response->get_error_code(), $response->get_error_data()['status'] ?? null ) );
	}

	/**
	 * The site kernel's idempotency service.
	 */
	private function service(): IdempotentRequests {
		return self::kernel()->container()->get( IdempotentRequests::class );
	}
}
