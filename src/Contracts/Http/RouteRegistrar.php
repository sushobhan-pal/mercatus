<?php
/**
 * The only way modules add REST routes (ARCH-8 point 4).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Contracts\Http;

/**
 * Routes live under /wp-json/mercatus/v1. Each endpoint is a WordPress endpoint array (methods, callback,
 * args) plus 'mercatus' => array( 'access' => …, 'resource' => …, 'summary' => … ). The registrar supplies
 * the permission callback from the access level, so an endpoint never sets its own; an endpoint without valid
 * metadata is refused and reported by GET /health.
 */
interface RouteRegistrar {

	/**
	 * Adds a route.
	 *
	 * @param string                                  $route     Path below the namespace, for example /modules/(?P<id>[a-z0-9.-]+)/activate.
	 * @param list<array<string, mixed>>              $endpoints WordPress endpoint arrays with route metadata.
	 * @param (callable(): array<string, mixed>)|null $schema    Response item schema, loaded only when the route is used.
	 */
	public function add( string $route, array $endpoints, ?callable $schema = null ): void;
}
