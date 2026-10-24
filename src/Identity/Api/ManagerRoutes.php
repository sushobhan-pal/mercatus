<?php
/**
 * The managers endpoints (API-2 bootstrap and system, IDN-15, VEN-16).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Identity\Api;

use Mercatus\Core\Contracts\Http\Access;
use Mercatus\Core\Contracts\Http\RouteProvider;
use Mercatus\Core\Contracts\Http\RouteRegistrar;
use Mercatus\Core\Identity\Application\ManagerDirectory;
use Mercatus\Core\Shared\Errors\DomainError;
use Mercatus\Core\Shared\Http\ApiError;
use Mercatus\Core\Shared\Http\Schema;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * GET /managers, POST /managers and POST /managers/{user_id}/remove, for administrators only (VEN-8). The
 * managers' shared permissions are a setting (`GET /settings/core.identity`).
 */
final class ManagerRoutes implements RouteProvider {

	/**
	 * Creates the routes.
	 *
	 * @param ManagerDirectory $managers Managers.
	 */
	public function __construct( private ManagerDirectory $managers ) {}

	public function routes( RouteRegistrar $routes ): void {
		$manager = static fn(): array => self::managerSchema();
		$routes->add(
			'/managers',
			array(
				$this->endpoint( 'GET', 'index', 'List the marketplace managers' ),
				$this->endpoint(
					'POST',
					'appoint',
					'Appoint a marketplace manager',
					array(
						'user_id' => array(
							'type'        => 'integer',
							'description' => 'The user to appoint; or give their email.',
						),
						'email'   => array(
							'type'        => 'string',
							'format'      => 'email',
							'description' => "The user's email, when the user ID isn't known.",
						),
					)
				),
			),
			$manager
		);
		$routes->add(
			'/managers/(?P<user_id>\d+)/remove',
			array(
				$this->endpoint(
					'POST',
					'remove',
					"End a marketplace manager's appointment",
					array(
						'user_id' => array(
							'type'        => 'integer',
							'description' => 'User ID.',
						),
					)
				),
			),
			static fn(): array => Schema::object(
				array(
					'user_id' => array( 'type' => 'integer' ),
					'manager' => array( 'type' => 'boolean' ),
				)
			)
		);
	}

	/**
	 * GET /managers: a short list, without paging.
	 */
	public function index(): WP_REST_Response {
		return new WP_REST_Response( array( 'items' => $this->managers->managers() ) );
	}

	/**
	 * POST /managers. Repeating it changes nothing.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function appoint( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		try {
			return new WP_REST_Response( $this->managers->appoint( self::user( $request ) ) );
		} catch ( DomainError $error ) {
			return self::errors( $error );
		}
	}

	/**
	 * POST /managers/{user_id}/remove. Repeating it changes nothing.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function remove( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id = (int) $request->get_param( 'user_id' );
		try {
			$this->managers->remove( $user_id );
		} catch ( DomainError $error ) {
			return self::errors( $error );
		}

		return new WP_REST_Response(
			array(
				'user_id' => $user_id,
				'manager' => false,
			)
		);
	}

	/**
	 * The user a request names, by ID or email.
	 *
	 * @param WP_REST_Request $request Request.
	 * @throws DomainError When it names no existing user.
	 */
	private static function user( WP_REST_Request $request ): int {
		$user_id = $request->get_param( 'user_id' );
		if ( is_int( $user_id ) ) {
			return $user_id;
		}
		$user = get_user_by( 'email', (string) $request->get_param( 'email' ) );
		if ( false === $user ) {
			throw DomainError::of( 'mercatus_user_unknown' );
		}

		return $user->ID;
	}

	/**
	 * The API-1 error for a domain error.
	 *
	 * @param DomainError $error Error.
	 */
	private static function errors( DomainError $error ): WP_Error {
		return ApiError::from(
			$error,
			array(
				'mercatus_user_unknown' => array( 404, __( 'There is no user with that ID or email.', 'mercatus' ) ),
			)
		);
	}

	/**
	 * An endpoint array with route metadata.
	 *
	 * @param string                              $method   HTTP method.
	 * @param string                              $callback Method of this class.
	 * @param string                              $summary  Summary for the API reference.
	 * @param array<string, array<string, mixed>> $args     Arguments.
	 * @return array<string, mixed>
	 */
	private function endpoint( string $method, string $callback, string $summary, array $args = array() ): array {
		return array(
			'methods'  => $method,
			'callback' => array( $this, $callback ),
			'args'     => $args,
			'mercatus' => array(
				'access'   => Access::Admin->value,
				'resource' => 'manager',
				'summary'  => $summary,
			),
		);
	}

	/**
	 * A manager.
	 *
	 * @return array<string, mixed>
	 */
	private static function managerSchema(): array {
		return Schema::object(
			array(
				'user_id'      => array( 'type' => 'integer' ),
				'display_name' => array( 'type' => 'string' ),
				'email'        => array( 'type' => 'string' ),
			)
		);
	}
}
