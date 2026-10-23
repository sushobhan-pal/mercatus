<?php
/**
 * A module's routes (ARCH-6, ARCH-8 point 4).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Contracts\Http;

/**
 * Named by a module manifest's `routes` field; called on rest_api_init only while the module is active.
 */
interface RouteProvider {

	/**
	 * Adds the module's routes.
	 *
	 * @param RouteRegistrar $routes Registrar.
	 */
	public function routes( RouteRegistrar $routes ): void;
}
