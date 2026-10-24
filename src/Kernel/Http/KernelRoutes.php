<?php
/**
 * The kernel's routes (API-2 bootstrap and system, KER-11).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Kernel\Http;

use Mercatus\Core\Contracts\Cache\CachePort;
use Mercatus\Core\Contracts\Http\Access;
use Mercatus\Core\Contracts\Http\RouteProvider;
use Mercatus\Core\Contracts\Http\RouteRegistrar;
use Mercatus\Core\Kernel\Health\HealthReport;
use Mercatus\Core\Kernel\Kernel;
use Mercatus\Core\Shared\Errors\DomainError;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * GET /features (public), GET /health, POST /system/cache/flush-group, GET /modules and POST
 * /modules/{id}/activate|deactivate (admin). Until `core.identity`, admin routes refuse everyone (KER-11).
 */
final class KernelRoutes implements RouteProvider {

	private const MODULE_ID = '(?P<id>[a-z]+\.[a-z][a-z0-9-]*)';

	/**
	 * Creates the routes.
	 *
	 * @param Kernel     $kernel Booted kernel.
	 * @param AccessGate $gate   Access checks, also used to show versions to administrators.
	 */
	public function __construct( private Kernel $kernel, private AccessGate $gate ) {}

	public function routes( RouteRegistrar $routes ): void {
		$routes->add( '/features', array( $this->endpoint( 'GET', 'features', Access::Open, 'feature', 'Active modules and their features' ) ), static fn(): array => self::featuresSchema() );
		$routes->add( '/health', array( $this->endpoint( 'GET', 'health', Access::Admin, 'health', 'Health report' ) ), static fn(): array => self::healthSchema() );
		$routes->add(
			'/system/cache/flush-group',
			array(
				$this->endpoint(
					'POST',
					'flushGroup',
					Access::Admin,
					'cache_group',
					'Clear a cache group',
					array(
						'group' => array(
							'type'        => 'string',
							'description' => 'Cache group, for example mercatus_stores.',
							'pattern'     => '^mercatus_[a-z_]+$',
							'required'    => true,
						),
					)
				),
			),
			static fn(): array => self::object(
				array(
					'group'   => array( 'type' => 'string' ),
					'flushed' => array( 'type' => 'boolean' ),
				)
			)
		);
		$routes->add( '/modules', array( $this->endpoint( 'GET', 'modules', Access::Admin, 'module', 'Optional modules and their state' ) ), static fn(): array => self::modulesSchema() );
		foreach ( array( 'activate', 'deactivate' ) as $action ) {
			$routes->add(
				'/modules/' . self::MODULE_ID . '/' . $action,
				array(
					$this->endpoint(
						'POST',
						$action,
						Access::Admin,
						'module',
						'activate' === $action ? 'Switch an optional module on' : 'Switch an optional module off',
						array(
							'id' => array(
								'type'        => 'string',
								'description' => 'Module ID.',
							),
						)
					),
				),
				static fn(): array => self::object(
					array(
						'id'     => array( 'type' => 'string' ),
						'active' => array( 'type' => 'boolean' ),
					)
				)
			);
		}
	}

	/**
	 * GET /features: versions only for administrators.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function features( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( $this->kernel->features()->toArray( true === $this->gate->check( Access::Admin, $request ) ) );
	}

	/**
	 * GET /health.
	 */
	public function health(): WP_REST_Response {
		return new WP_REST_Response( $this->kernel->container()->get( HealthReport::class )->build() );
	}

	/**
	 * POST /system/cache/flush-group: bumps the group's revision (PLAT-5).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function flushGroup( WP_REST_Request $request ): WP_REST_Response {
		$group = (string) $request->get_param( 'group' );
		$this->kernel->container()->get( CachePort::class )->flushGroup( $group );

		return new WP_REST_Response(
			array(
				'group'   => $group,
				'flushed' => true,
			)
		);
	}

	/**
	 * GET /modules.
	 */
	public function modules(): WP_REST_Response {
		return new WP_REST_Response( array( 'modules' => $this->kernel->activation()->optionalModules() ) );
	}

	/**
	 * POST /modules/{id}/activate.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function activate( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->switchModule( $request, true );
	}

	/**
	 * POST /modules/{id}/deactivate.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function deactivate( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->switchModule( $request, false );
	}

	/**
	 * Switches a module and reports the result.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param bool            $on      Whether to switch it on.
	 */
	private function switchModule( WP_REST_Request $request, bool $on ): WP_REST_Response|WP_Error {
		$id = (string) $request->get_param( 'id' );
		try {
			$this->kernel->switchModule( $id, $on );
		} catch ( DomainError $error ) {
			return ErrorResponse::from( $error );
		}

		return new WP_REST_Response(
			array(
				'id'     => $id,
				'active' => $on,
			)
		);
	}

	/**
	 * An endpoint array with route metadata.
	 *
	 * @param string                              $method   HTTP method.
	 * @param string                              $callback Method of this class.
	 * @param Access                              $access   Access level.
	 * @param string                              $resource_type Resource type.
	 * @param string                              $summary  Summary for the API reference.
	 * @param array<string, array<string, mixed>> $args     Arguments.
	 * @return array<string, mixed>
	 */
	private function endpoint( string $method, string $callback, Access $access, string $resource_type, string $summary, array $args = array() ): array {
		return array(
			'methods'  => $method,
			'callback' => array( $this, $callback ),
			'args'     => $args,
			'mercatus' => array(
				'access'   => $access->value,
				'resource' => $resource_type,
				'summary'  => $summary,
			),
		);
	}

	/**
	 * Schema of GET /features.
	 *
	 * @return array<string, mixed>
	 */
	private static function featuresSchema(): array {
		return self::object(
			array(
				'modules'  => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'id'      => array(
								'type'     => 'string',
								'required' => true,
							),
							'version' => array( 'type' => 'string' ),
						),
					),
				),
				'features' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
			)
		);
	}

	/**
	 * Schema of GET /health.
	 *
	 * @return array<string, mixed>
	 */
	private static function healthSchema(): array {
		return self::object(
			array(
				'status' => array(
					'type' => 'string',
					'enum' => array( 'ok', 'warning', 'error' ),
				),
				'checks' => array(
					'type'  => 'array',
					'items' => self::object(
						array(
							'id'      => array( 'type' => 'string' ),
							'status'  => array(
								'type' => 'string',
								'enum' => array( 'ok', 'warning', 'error' ),
							),
							'code'    => array( 'type' => 'string' ),
							'details' => array( 'type' => 'object' ),
						)
					),
				),
			)
		);
	}

	/**
	 * Schema of GET /modules.
	 *
	 * @return array<string, mixed>
	 */
	private static function modulesSchema(): array {
		return self::object(
			array(
				'modules' => array(
					'type'  => 'array',
					'items' => self::object(
						array(
							'id'      => array( 'type' => 'string' ),
							'version' => array( 'type' => 'string' ),
							'active'  => array( 'type' => 'boolean' ),
						)
					),
				),
			)
		);
	}

	/**
	 * An object schema whose properties are all required.
	 *
	 * @param array<string, array<string, mixed>> $properties Properties.
	 * @return array<string, mixed>
	 */
	private static function object( array $properties ): array {
		foreach ( $properties as $name => $property ) {
			$properties[ $name ] = $property + array( 'required' => true );
		}

		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'type'       => 'object',
			'properties' => $properties,
		);
	}
}
