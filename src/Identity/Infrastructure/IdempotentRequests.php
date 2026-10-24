<?php
/**
 * Runs POSTs at most once per Idempotency-Key (PLAT-7, IDN-9).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Identity\Infrastructure;

use Closure;
use DateInterval;
use Mercatus\Core\Contracts\Settings\SettingsReader;
use Mercatus\Core\Identity\Application\IdentitySettings;
use Mercatus\Core\Shared\Clock\Clock;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * A repeat of a finished request returns its stored response with `Idempotent-Replayed: true`; one still
 * running gets 409, and one with a different payload 422. Domain errors (4xx) are stored like successes; a 5xx
 * releases the key, so the client can retry.
 */
final class IdempotentRequests {

	private const KEY = '/^[\x21-\x7E]{1,255}$/';

	/**
	 * Creates the service.
	 *
	 * @param IdempotencyStore $store    Rows.
	 * @param RequestSubject   $subject  Who the key belongs to.
	 * @param SettingsReader   $settings Retention.
	 * @param Clock            $clock    Current time.
	 */
	public function __construct( private IdempotencyStore $store, private RequestSubject $subject, private SettingsReader $settings, private Clock $clock ) {}

	/**
	 * Runs a POST under its key, if it has one.
	 *
	 * @param WP_REST_Request                              $request  Request.
	 * @param bool                                         $required Whether the route requires a key.
	 * @param Closure(): (WP_REST_Response|WP_Error)       $next     The endpoint.
	 */
	public function handle( WP_REST_Request $request, bool $required, Closure $next ): WP_REST_Response|WP_Error {
		$key = $request->get_header( 'idempotency_key' );
		if ( null === $key ) {
			return $required
				? new WP_Error( 'mercatus_idempotency_key_required', __( 'Send an Idempotency-Key header with this request.', 'mercatus' ), array( 'status' => 400 ) )
				: $next();
		}
		if ( 1 !== preg_match( self::KEY, $key ) ) {
			return new WP_Error( 'mercatus_idempotency_key_invalid', __( 'An Idempotency-Key is 1 to 255 printable characters.', 'mercatus' ), array( 'status' => 400 ) );
		}

		$fingerprint = $this->subject->key();
		$route       = substr( $request->get_method() . ' ' . $request->get_route(), 0, 191 );
		$hash        = self::hash( $request );
		$now         = $this->clock->now();
		if ( ! $this->store->claim( $fingerprint, $route, $key, $hash, $now, $now->add( new DateInterval( 'PT' . $this->settings->int( IdentitySettings::IDEMPOTENCY_RETENTION_HOURS ) . 'H' ) ) ) ) {
			return $this->repeat( $this->store->find( $fingerprint, $route, $key ), $hash );
		}

		$row      = $this->store->find( $fingerprint, $route, $key );
		$response = $next();
		$status   = $response instanceof WP_Error ? self::status( $response ) : $response->get_status();
		if ( null !== $row ) {
			if ( $status >= 500 ) {
				$this->store->release( $row['id'] );
			} else {
				$this->store->complete( $row['id'], $status, self::store( $response ) );
			}
		}

		return $response;
	}

	/**
	 * The answer to a repeated key.
	 *
	 * @param array{id: int, request_hash: string, status: string, response_status: int|null, response_body: string|null}|null $row  Its row.
	 * @param string                                                                                                       $hash This request's hash.
	 */
	private function repeat( ?array $row, string $hash ): WP_REST_Response|WP_Error {
		if ( null === $row || $row['request_hash'] !== $hash ) {
			return new WP_Error( 'mercatus_idempotency_mismatch', __( 'This Idempotency-Key was used for a different request.', 'mercatus' ), array( 'status' => 422 ) );
		}
		if ( 'completed' !== $row['status'] || null === $row['response_status'] ) {
			return new WP_Error( 'mercatus_idempotency_in_progress', __( 'A request with this Idempotency-Key is still running.', 'mercatus' ), array( 'status' => 409 ) );
		}

		$stored = json_decode( (string) $row['response_body'], true );
		$stored = is_array( $stored ) ? $stored : array();
		if ( isset( $stored['error'] ) && is_array( $stored['error'] ) ) {
			$error = new WP_Error( (string) ( $stored['error']['code'] ?? 'mercatus_error' ), (string) ( $stored['error']['message'] ?? '' ), $stored['error']['data'] ?? array( 'status' => $row['response_status'] ) );
			$error->add_data( array_merge( (array) $error->get_error_data(), array( 'replayed' => true ) ) );

			return $error;
		}
		$response = new WP_REST_Response( $stored['data'] ?? null, $row['response_status'] );
		$response->header( 'Idempotent-Replayed', 'true' );

		return $response;
	}

	/**
	 * A response as stored JSON.
	 *
	 * @param WP_REST_Response|WP_Error $response Response.
	 */
	private static function store( WP_REST_Response|WP_Error $response ): string {
		$stored = $response instanceof WP_Error
			? array(
				'error' => array(
					'code'    => $response->get_error_code(),
					'message' => $response->get_error_message(),
					'data'    => $response->get_error_data(),
				),
			)
			: array( 'data' => $response->get_data() );

		return (string) wp_json_encode( $stored );
	}

	/**
	 * An error's HTTP status.
	 *
	 * @param WP_Error $error Error.
	 */
	private static function status( WP_Error $error ): int {
		$data = $error->get_error_data();

		return is_array( $data ) && is_int( $data['status'] ?? null ) ? $data['status'] : 500;
	}

	/**
	 * The hash of a request's method, route and canonical body.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	private static function hash( WP_REST_Request $request ): string {
		$json = $request->get_json_params();
		$body = array() !== $json ? $json : $request->get_body_params();

		return hash( 'sha256', $request->get_method() . "\n" . $request->get_route() . "\n" . wp_json_encode( self::canonical( $body ) ) );
	}

	/**
	 * A value with every object's keys sorted, so equal payloads hash alike.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private static function canonical( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( ! array_is_list( $value ) ) {
			ksort( $value );
		}

		return array_map( array( self::class, 'canonical' ), $value );
	}
}
