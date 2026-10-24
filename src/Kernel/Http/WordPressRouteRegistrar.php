<?php
/**
 * RouteRegistrar over register_rest_route() (ARCH-8 point 4, KER-11).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Kernel\Http;

use Mercatus\Core\Contracts\Http\RouteRegistrar;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Validates each endpoint's route metadata, attaches the access gate as its permission callback and registers
 * the route. Invalid endpoints are skipped and remembered for the health report: one module's mistake never
 * takes the site down, and never opens a route.
 */
final class WordPressRouteRegistrar implements RouteRegistrar {

	public const NAMESPACE = 'mercatus/v1';

	/**
	 * Refused endpoints as "METHODS route".
	 *
	 * @var list<string>
	 */
	private array $refused = array();

	/**
	 * Creates the registrar.
	 *
	 * @param AccessGate    $gate     Knows which permissions exist.
	 * @param RoutePipeline $pipeline What every endpoint runs through.
	 */
	public function __construct( private AccessGate $gate, private RoutePipeline $pipeline ) {}

	public function add( string $route, array $endpoints, ?callable $schema = null ): void {
		if ( '' === $route || '0' === $route || ! str_starts_with( $route, '/' ) ) {
			$this->refused[] = '? ' . $route;
			return;
		}
		$accepted = array();
		foreach ( $endpoints as $endpoint ) {
			$meta = RouteMeta::of( $endpoint );
			if ( null === $meta || ( null !== $meta->permission && ! $this->gate->knows( $meta->permission ) ) || isset( $endpoint['permission_callback'] ) || ! is_callable( $endpoint['callback'] ?? null ) ) {
				$this->refused[] = ( is_string( $endpoint['methods'] ?? null ) ? $endpoint['methods'] : '?' ) . ' ' . $route;
				continue;
			}
			$callback                        = $endpoint['callback'];
			$endpoint['permission_callback'] = fn( WP_REST_Request $request ): true|WP_Error => $this->pipeline->permit( $meta, $request );
			$endpoint['callback']            = fn( WP_REST_Request $request ): WP_REST_Response|WP_Error => $this->pipeline->run( $meta, $callback, $request );
			$accepted[]                      = $endpoint;
		}
		if ( array() === $accepted ) {
			return;
		}
		if ( null !== $schema ) {
			$accepted['schema'] = $schema;
		}
		register_rest_route( self::NAMESPACE, $route, $accepted );
	}

	/**
	 * Endpoints refused for missing or invalid metadata.
	 *
	 * @return list<string>
	 */
	public function refused(): array {
		return $this->refused;
	}
}
