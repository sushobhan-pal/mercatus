<?php
/**
 * The API-client endpoints (API-2, IDN-18).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Identity\Api;

use Mercatus\Core\Contracts\Http\Access;
use Mercatus\Core\Contracts\Http\RouteProvider;
use Mercatus\Core\Contracts\Http\RouteRegistrar;
use Mercatus\Core\Contracts\Identity\CurrentActor;
use Mercatus\Core\Identity\Application\ApiClientDirectory;
use Mercatus\Core\Shared\Errors\DomainError;
use Mercatus\Core\Shared\Http\ApiError;
use Mercatus\Core\Shared\Http\ETag;
use Mercatus\Core\Shared\Http\ListDefinition;
use Mercatus\Core\Shared\Http\Page;
use Mercatus\Core\Shared\Http\Schema;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Administrators, and managers holding identity.api_clients.manage, register, change and revoke clients and
 * issue integration passwords; everyone sees and revokes their own app sign-ins.
 */
final class ApiClientRoutes implements RouteProvider {

	private const CLIENT = '/api-clients/(?P<id>\d+)';

	/**
	 * Creates the routes.
	 *
	 * @param ApiClientDirectory $clients API clients.
	 * @param CurrentActor       $actors  The current actor.
	 */
	public function __construct( private ApiClientDirectory $clients, private CurrentActor $actors ) {}

	public function routes( RouteRegistrar $routes ): void {
		$client = fn(): array => $this->clientSchema();
		$routes->add(
			'/api-clients',
			array(
				$this->endpoint( 'GET', 'index', Access::Admin, 'List API clients', self::listing()->args() ),
				$this->endpoint( 'POST', 'create', Access::Admin, 'Register an API client', $this->fields( true ) ),
			),
			$client
		);
		$routes->add( self::CLIENT, array( $this->endpoint( 'PATCH', 'update', Access::Admin, 'Change an API client', $this->fields( false ) ) ), $client );
		$routes->add( self::CLIENT . '/revoke', array( $this->endpoint( 'POST', 'revoke', Access::Admin, 'Revoke an API client' ) ), $client );
		$routes->add( self::CLIENT . '/passwords', array( $this->endpoint( 'POST', 'password', Access::Admin, "Create an integration's application password" ) ), static fn(): array => self::passwordSchema() );
		$routes->add( '/me/app-passwords', array( $this->endpoint( 'GET', 'mine', Access::Authenticated, 'Your app sign-ins' ) ), static fn(): array => self::appPasswordsSchema() );
		$routes->add( '/me/app-passwords/(?P<uuid>[0-9a-f-]{36})/revoke', array( $this->endpoint( 'POST', 'revokeMine', Access::Authenticated, 'Revoke one of your app sign-ins' ) ) );
	}

	/**
	 * GET /api-clients.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function index( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->guard(
			function () use ( $request ): WP_REST_Response {
				$list = self::listing()->parse( $request->get_params() );

				return Page::response( $this->clients->page( $list ), $list, static fn( array $row ): array => $row['client'] );
			}
		);
	}

	/**
	 * POST /api-clients.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function create( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->guard(
			function () use ( $request ): WP_REST_Response {
				$kind = $request->get_param( 'kind' );
				$user = $request->get_param( 'user_id' );
				if ( 'integration' === $kind && ! is_int( $user ) ) {
					throw DomainError::of( 'mercatus_api_client_invalid', array( 'field' => 'user_id' ) );
				}
				list( $client, $version, $app_id ) = $this->clients->register(
					(string) $request->get_param( 'name' ),
					'integration' === $kind ? $user : null,
					self::strings( $request->get_param( 'scopes' ) ),
					self::strings( $request->get_param( 'ip_allow_list' ) ),
					(int) ( $request->get_param( 'password_lifetime_days' ) ?? 0 )
				);

				return self::client( $client + array( 'app_id' => $app_id ), $version, 201 );
			}
		);
	}

	/**
	 * PATCH /api-clients/{id}: changes only the client at the version If-Match names (PLAT-9).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function update( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->guard(
			function () use ( $request ): WP_REST_Response {
				$expected                 = ETag::version( $request->get_header( 'if_match' ) ) ?? throw DomainError::of( 'mercatus_version_conflict' );
				$changes                  = array_intersect_key( $request->get_params(), array_flip( array( 'name', 'scopes', 'ip_allow_list', 'password_lifetime_days' ) ) );
				list( $client, $version ) = $this->clients->update( (int) $request->get_param( 'id' ), $expected, $changes );

				return self::client( $client, $version );
			}
		);
	}

	/**
	 * POST /api-clients/{id}/revoke.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function revoke( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->guard(
			function () use ( $request ): WP_REST_Response {
				list( $client, $version ) = $this->clients->revoke( (int) $request->get_param( 'id' ) );

				return self::client( $client, $version );
			}
		);
	}

	/**
	 * POST /api-clients/{id}/passwords: the password is shown only in this response.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function password( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->guard(
			function () use ( $request ): WP_REST_Response {
				list( $password, $uuid ) = $this->clients->createPassword( (int) $request->get_param( 'id' ) );

				return new WP_REST_Response(
					array(
						'uuid'     => $uuid,
						'password' => $password,
					),
					201
				);
			}
		);
	}

	/**
	 * GET /me/app-passwords.
	 */
	public function mine(): WP_REST_Response {
		return new WP_REST_Response( array( 'items' => $this->clients->appPasswords( $this->actors->identity()->userId ) ) );
	}

	/**
	 * POST /me/app-passwords/{uuid}/revoke.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function revokeMine( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$uuid = (string) $request->get_param( 'uuid' );
		if ( ! $this->clients->revokeAppPassword( $this->actors->identity()->userId, $uuid ) ) {
			return self::errors( DomainError::of( 'mercatus_app_password_unknown' ) );
		}

		return new WP_REST_Response(
			array(
				'uuid'    => $uuid,
				'revoked' => true,
			)
		);
	}

	/**
	 * Runs work, turning core.identity's domain errors into REST errors.
	 *
	 * @param callable(): WP_REST_Response $work Work.
	 */
	private function guard( callable $work ): WP_REST_Response|WP_Error {
		try {
			return $work();
		} catch ( DomainError $error ) {
			return self::errors( $error );
		}
	}

	/**
	 * core.identity's error statuses and messages (ARCH-7).
	 *
	 * @param DomainError $error Error.
	 */
	private static function errors( DomainError $error ): WP_Error {
		return ApiError::from(
			$error,
			array(
				'mercatus_api_client_unknown'         => array( 404, __( 'There is no such API client.', 'mercatus' ) ),
				'mercatus_api_client_invalid'         => array( 422, __( 'One of the API client\'s values is not allowed.', 'mercatus' ) ),
				'mercatus_api_client_revoked'         => array( 409, __( 'This API client was revoked.', 'mercatus' ) ),
				'mercatus_api_client_not_integration' => array( 409, __( 'Only integrations get passwords here; app users sign in themselves.', 'mercatus' ) ),
				'mercatus_api_client_password_failed' => array( 409, __( 'WordPress could not create the password.', 'mercatus' ) ),
				'mercatus_version_conflict'           => array( 409, __( 'Someone changed this in the meantime; reload and try again.', 'mercatus' ) ),
				'mercatus_app_password_unknown'       => array( 404, __( 'There is no such app sign-in.', 'mercatus' ) ),
			)
		);
	}

	/**
	 * A client response with its ETag.
	 *
	 * @param array<string, mixed> $data    Client data.
	 * @param int                  $version Version.
	 * @param int                  $status  Status.
	 */
	private static function client( array $data, int $version, int $status = 200 ): WP_REST_Response {
		$response = new WP_REST_Response( $data, $status );
		$response->header( 'ETag', ETag::forVersion( $version ) );

		return $response;
	}

	/**
	 * The client list's allow-list.
	 */
	private static function listing(): ListDefinition {
		return new ListDefinition( array( 'id' => 'int' ), array( 'status' => array( 'active', 'revoked' ) ) );
	}

	/**
	 * A list of strings from a parameter.
	 *
	 * @param mixed $value Value.
	 * @return list<string>
	 */
	private static function strings( mixed $value ): array {
		return is_array( $value ) ? array_values( array_filter( $value, 'is_string' ) ) : array();
	}

	/**
	 * An endpoint.
	 *
	 * @param string                              $method   HTTP method.
	 * @param string                              $callback Method of this class.
	 * @param Access                              $access   Access level.
	 * @param string                              $summary  Summary.
	 * @param array<string, array<string, mixed>> $args     Arguments.
	 * @return array<string, mixed>
	 */
	private function endpoint( string $method, string $callback, Access $access, string $summary, array $args = array() ): array {
		return array(
			'methods'  => $method,
			'callback' => array( $this, $callback ),
			'args'     => $args,
			'mercatus' => array_filter(
				array(
					'access'     => $access->value,
					'permission' => Access::Admin === $access ? IdentityPermissions::API_CLIENTS_MANAGE : null,
					'resource'   => Access::Admin === $access ? 'api_client' : 'app_password',
					'summary'    => $summary,
				)
			),
		);
	}

	/**
	 * The writable fields.
	 *
	 * @param bool $creating Whether registering, where name, kind and scopes are required.
	 * @return array<string, array<string, mixed>>
	 */
	private function fields( bool $creating ): array {
		$fields = array(
			'name'                   => array(
				'type'      => 'string',
				'minLength' => 1,
				'maxLength' => 191,
				'required'  => $creating,
			),
			'scopes'                 => array(
				'type'     => 'array',
				'items'    => array( 'type' => 'string' ),
				'minItems' => 1,
				'required' => $creating,
			),
			'ip_allow_list'          => array(
				'type'  => 'array',
				'items' => array( 'type' => 'string' ),
			),
			'password_lifetime_days' => array(
				'type'    => 'integer',
				'minimum' => 0,
				'maximum' => 3650,
			),
		);
		if ( $creating ) {
			$fields['kind']    = array(
				'type'     => 'string',
				'enum'     => array( 'integration', 'app' ),
				'required' => true,
			);
			$fields['user_id'] = array(
				'type'        => 'integer',
				'description' => 'The user an integration acts as.',
			);
		}

		return $fields;
	}

	/**
	 * Schema of a client.
	 *
	 * @return array<string, mixed>
	 */
	private function clientSchema(): array {
		$strings = array(
			'type'  => 'array',
			'items' => array( 'type' => 'string' ),
		);

		return Schema::object(
			array(
				'id'                     => array( 'type' => 'integer' ),
				'name'                   => array( 'type' => 'string' ),
				'kind'                   => array(
					'type' => 'string',
					'enum' => array( 'integration', 'app' ),
				),
				'user_id'                => array( 'type' => array( 'integer', 'null' ) ),
				'scopes'                 => $strings,
				'ip_allow_list'          => $strings,
				'password_lifetime_days' => array( 'type' => 'integer' ),
				'status'                 => array(
					'type' => 'string',
					'enum' => array( 'active', 'revoked' ),
				),
				'last_used_at'           => array( 'type' => array( 'string', 'null' ) ),
				'created_at'             => array( 'type' => 'string' ),
				'updated_at'             => array( 'type' => 'string' ),
				'version'                => array( 'type' => 'integer' ),
			)
		);
	}

	/**
	 * Schema of a new password.
	 *
	 * @return array<string, mixed>
	 */
	private static function passwordSchema(): array {
		return Schema::object(
			array(
				'uuid'     => array( 'type' => 'string' ),
				'password' => array( 'type' => 'string' ),
			)
		);
	}

	/**
	 * Schema of the app sign-ins list.
	 *
	 * @return array<string, mixed>
	 */
	private static function appPasswordsSchema(): array {
		return Schema::object(
			array(
				'items' => array(
					'type'  => 'array',
					'items' => Schema::object(
						array(
							'uuid'      => array( 'type' => 'string' ),
							'name'      => array( 'type' => 'string' ),
							'client'    => array( 'type' => array( 'string', 'null' ) ),
							'created'   => array( 'type' => 'string' ),
							'last_used' => array( 'type' => array( 'string', 'null' ) ),
						)
					),
				),
			)
		);
	}
}
