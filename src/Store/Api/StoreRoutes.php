<?php
/**
 * core.store's endpoints (API-2 Stores, STO-3, STO-15).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Store\Api;

use Mercatus\Core\Contracts\Http\Access;
use Mercatus\Core\Contracts\Http\RouteProvider;
use Mercatus\Core\Contracts\Http\RouteRegistrar;
use Mercatus\Core\Shared\Errors\DomainError;
use Mercatus\Core\Shared\Http\ApiError;
use Mercatus\Core\Shared\Http\ETag;
use Mercatus\Core\Shared\Http\ListDefinition;
use Mercatus\Core\Shared\Http\Page;
use Mercatus\Core\Shared\Http\Schema;
use Mercatus\Core\Store\Application\StoreAccess;
use Mercatus\Core\Store\Application\StoreDirectory;
use Mercatus\Core\Store\Application\StoreFields;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The store list and store pages are public and edge-cacheable (PLAT-3 L3); a response that depends on who
 * asks (an administrator's filtered list, an owner previewing a hidden store) is marked private so a CDN never
 * serves it to someone else (TM-38). The vendor changes their own store through /me/store; administrators and
 * managers holding stores.manage change any through /stores/{id}.
 */
final class StoreRoutes implements RouteProvider {

	private const BY_ID = '/stores/(?P<id>\d+)';

	private const BY_SLUG = '/stores/(?P<slug>[a-z0-9-]+)';

	private const PRIVATE = 'private, no-cache';

	/**
	 * Creates the routes.
	 *
	 * @param StoreDirectory $stores Stores.
	 */
	public function __construct( private StoreDirectory $stores ) {}

	public function routes( RouteRegistrar $routes ): void {
		$public = static fn(): array => self::publicSchema();
		$full   = static fn(): array => self::fullSchema();
		$routes->add( '/stores', array( $this->endpoint( 'GET', 'index', Access::Open, null, 'List stores', self::listing()->args() + self::searchArg() ) ), $public );
		// Before the slug route: a slug is never all digits (STO-9), so a number is always an ID.
		$routes->add(
			self::BY_ID,
			array(
				$this->endpoint( 'GET', 'show', Access::Admin, StoreAccess::MANAGE, 'A store, every field' ),
				$this->endpoint( 'PATCH', 'update', Access::Admin, StoreAccess::MANAGE, 'Change a store', self::changeArgs() ),
			),
			$full
		);
		$routes->add( self::BY_SLUG, array( $this->endpoint( 'GET', 'page', Access::Open, null, 'A store, as the public sees it' ) ), $public );
		$routes->add(
			'/me/store',
			array(
				$this->endpoint( 'GET', 'mine', Access::Tenant, null, 'Your store, every field' ),
				$this->endpoint( 'PATCH', 'updateMine', Access::Tenant, null, 'Change your store', self::changeArgs() ),
			),
			$full
		);
	}

	/**
	 * GET /stores: listed stores; with a visibility or status filter, every store, for administrators.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function index( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->guard(
			function () use ( $request ): WP_REST_Response {
				$list     = self::listing()->parse( $request->get_params() );
				$search   = trim( (string) $request->get_param( 'search' ) );
				$filtered = array() !== $list->filters;
				$rows     = $filtered ? $this->stores->all( $list, $search ) : $this->stores->listed( $list, $search );
				$response = Page::response( $rows, $list, static fn( array $row ): array => $row['store'] );
				if ( $filtered ) {
					$response->header( 'Cache-Control', self::PRIVATE );
				}

				return $response;
			}
		);
	}

	/**
	 * GET /stores/{slug}.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function page( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->guard(
			function () use ( $request ): WP_REST_Response {
				$result   = $this->stores->bySlug( (string) $request->get_param( 'slug' ) );
				$response = self::item( $result['store'], $result['version'] );
				if ( ! $result['listed'] ) {
					$response->header( 'Cache-Control', self::PRIVATE );
				}

				return $response;
			}
		);
	}

	/**
	 * GET /stores/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function show( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->guard( fn(): WP_REST_Response => self::result( $this->stores->byId( (int) $request->get_param( 'id' ) ) ) );
	}

	/**
	 * PATCH /stores/{id}: changes only the version If-Match names (PLAT-9).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function update( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->guard( fn(): WP_REST_Response => self::result( $this->stores->update( (int) $request->get_param( 'id' ), self::changes( $request ), self::expected( $request ) ) ) );
	}

	/**
	 * GET /me/store.
	 */
	public function mine(): WP_REST_Response|WP_Error {
		return $this->guard( fn(): WP_REST_Response => self::result( $this->stores->mine() ) );
	}

	/**
	 * PATCH /me/store: changes only the version If-Match names (PLAT-9).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function updateMine( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->guard( fn(): WP_REST_Response => self::result( $this->stores->updateMine( self::changes( $request ), self::expected( $request ) ) ) );
	}

	/**
	 * Runs work, turning core.store's domain errors into REST errors.
	 *
	 * @param callable(): WP_REST_Response $work Work.
	 */
	private function guard( callable $work ): WP_REST_Response|WP_Error {
		try {
			return $work();
		} catch ( DomainError $error ) {
			return ApiError::from(
				$error,
				array(
					'mercatus_store_unknown'            => array( 404, __( 'There is no such store.', 'mercatus' ) ),
					'mercatus_store_invalid'            => array( 422, __( 'Please check the highlighted field.', 'mercatus' ) ),
					'mercatus_store_slug_invalid'       => array( 422, __( 'A store address uses 3 to 60 lower-case letters, digits and hyphens, isn’t only digits, and isn’t a reserved word.', 'mercatus' ) ),
					'mercatus_store_slug_taken'         => array( 409, __( 'Another store already uses that address.', 'mercatus' ) ),
					'mercatus_store_slug_locked'        => array( 403, __( 'The marketplace doesn’t let vendors change their store’s address.', 'mercatus' ) ),
					'mercatus_store_filter_not_allowed' => array( 403, __( 'Only administrators can list hidden or suspended stores.', 'mercatus' ) ),
					'mercatus_version_conflict'         => array( 409, __( 'Someone changed this in the meantime; reload and try again.', 'mercatus' ) ),
				)
			);
		}
	}

	/**
	 * The fields a change names, and nothing else (SEC-3).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	private static function changes( WP_REST_Request $request ): array {
		return array_intersect_key( $request->get_params(), array_flip( StoreFields::CHANGEABLE ) );
	}

	/**
	 * The version If-Match names.
	 *
	 * @param WP_REST_Request $request Request.
	 * @throws DomainError When it names none.
	 */
	private static function expected( WP_REST_Request $request ): int {
		return ETag::version( $request->get_header( 'if_match' ) ) ?? throw DomainError::of( 'mercatus_version_conflict' );
	}

	/**
	 * A store response with its ETag.
	 *
	 * @param array{store: array<string, mixed>, version: int} $result Store and version.
	 */
	private static function result( array $result ): WP_REST_Response {
		return self::item( $result['store'], $result['version'] );
	}

	/**
	 * A store response with its ETag.
	 *
	 * @param array<string, mixed> $store   Store.
	 * @param int                  $version Version.
	 */
	private static function item( array $store, int $version ): WP_REST_Response {
		$response = new WP_REST_Response( $store );
		$response->header( 'ETag', ETag::forVersion( $version ) );

		return $response;
	}

	/**
	 * The store list's allow-list: by name or newest; the filters are for administrators.
	 */
	private static function listing(): ListDefinition {
		return new ListDefinition(
			array(
				'name' => 'string',
				'id'   => 'int',
			),
			array(
				'visibility' => StoreFields::VISIBILITIES,
				'status'     => StoreFields::STATUSES,
			)
		);
	}

	/**
	 * The name search.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function searchArg(): array {
		return array(
			'search' => array(
				'type'        => 'string',
				'maxLength'   => 50,
				'description' => 'Stores whose name starts with this.',
			),
		);
	}

	/**
	 * The fields a change may name.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function changeArgs(): array {
		$args = array(
			'name'       => array(
				'type'      => 'string',
				'minLength' => 1,
				'maxLength' => StoreFields::NAME_MAX,
			),
			'slug'       => array(
				'type'      => 'string',
				'minLength' => StoreFields::SLUG_MIN,
				'maxLength' => StoreFields::SLUG_MAX,
			),
			'show_email' => array( 'type' => 'boolean' ),
			'show_phone' => array( 'type' => 'boolean' ),
			'visibility' => array(
				'type' => 'string',
				'enum' => StoreFields::VISIBILITIES,
			),
			'address'    => array(
				'type'        => 'object',
				'description' => 'The default location\'s address: address_1, address_2, city, state, postcode and country (ISO 3166-1 alpha-2).',
			),
		);
		foreach ( StoreFields::TEXT as $field => $max ) {
			$args[ $field ] = array(
				'type'      => 'string',
				'maxLength' => $max,
			);
		}

		return $args;
	}

	/**
	 * An endpoint.
	 *
	 * @param string                              $method     HTTP method.
	 * @param string                              $callback   Method of this class.
	 * @param Access                              $access     Access level.
	 * @param string|null                         $permission Named permission.
	 * @param string                              $summary    Summary.
	 * @param array<string, array<string, mixed>> $args       Arguments.
	 * @return array<string, mixed>
	 */
	private function endpoint( string $method, string $callback, Access $access, ?string $permission, string $summary, array $args = array() ): array {
		return array(
			'methods'  => $method,
			'callback' => array( $this, $callback ),
			'args'     => $args,
			'mercatus' => array_filter(
				array(
					'access'     => $access->value,
					'permission' => $permission,
					'resource'   => 'store',
					'summary'    => $summary,
				)
			),
		);
	}

	/**
	 * Schema of a store as the public sees it.
	 *
	 * @return array<string, mixed>
	 */
	private static function publicSchema(): array {
		return Schema::object(
			array(
				'id'          => array( 'type' => 'integer' ),
				'slug'        => array( 'type' => 'string' ),
				'name'        => array( 'type' => 'string' ),
				'tagline'     => array( 'type' => 'string' ),
				'description' => array( 'type' => 'string' ),
				'logo_id'     => array( 'type' => array( 'integer', 'null' ) ),
				'banner_id'   => array( 'type' => array( 'integer', 'null' ) ),
				'contact'     => array(
					'type'        => 'object',
					'description' => 'The contact details the vendor shows: email, phone.',
				),
				'policies'    => self::policiesSchema(),
				'location'    => Schema::object(
					array(
						'city'    => array( 'type' => 'string' ),
						'state'   => array( 'type' => 'string' ),
						'country' => array( 'type' => 'string' ),
					)
				),
				'created_at'  => array( 'type' => 'string' ),
			)
		);
	}

	/**
	 * Schema of a store as its owner and administrators see it.
	 *
	 * @return array<string, mixed>
	 */
	private static function fullSchema(): array {
		return Schema::object(
			array(
				'id'            => array( 'type' => 'integer' ),
				'vendor_id'     => array( 'type' => 'integer' ),
				'slug'          => array( 'type' => 'string' ),
				'name'          => array( 'type' => 'string' ),
				'tagline'       => array( 'type' => 'string' ),
				'description'   => array( 'type' => 'string' ),
				'logo_id'       => array( 'type' => array( 'integer', 'null' ) ),
				'banner_id'     => array( 'type' => array( 'integer', 'null' ) ),
				'contact_email' => array( 'type' => 'string' ),
				'contact_phone' => array( 'type' => 'string' ),
				'show_email'    => array( 'type' => 'boolean' ),
				'show_phone'    => array( 'type' => 'boolean' ),
				'policies'      => self::policiesSchema(),
				'visibility'    => array(
					'type' => 'string',
					'enum' => StoreFields::VISIBILITIES,
				),
				'status'        => array(
					'type' => 'string',
					'enum' => StoreFields::STATUSES,
				),
				'listed'        => array( 'type' => 'boolean' ),
				'address'       => array(
					'type'        => 'object',
					'description' => 'The default location\'s address.',
				),
				'coordinates'   => array(
					'type'        => array( 'object', 'null' ),
					'description' => 'latitude, longitude and geo_cell, once geocoded.',
				),
				'created_at'    => array( 'type' => 'string' ),
				'updated_at'    => array( 'type' => 'string' ),
				'version'       => array( 'type' => 'integer' ),
			)
		);
	}

	/**
	 * Schema of the three policies.
	 *
	 * @return array<string, mixed>
	 */
	private static function policiesSchema(): array {
		return Schema::object(
			array(
				'shipping' => array( 'type' => 'string' ),
				'returns'  => array( 'type' => 'string' ),
				'privacy'  => array( 'type' => 'string' ),
			)
		);
	}
}
