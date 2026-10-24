<?php
/**
 * The registrar refuses routes without valid metadata (ARCH-8 point 4, KER-11).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Integration\Http;

use Mercatus\Core\Kernel\Http\FailClosedAccessGate;
use Mercatus\Core\Kernel\Http\WordPressRouteRegistrar;
use Mercatus\Core\Kernel\Kernel;
use WP_UnitTestCase;

/**
 * Endpoints without access metadata, with an invalid level, with their own permission callback or without a
 * callback are refused and reported; the kernel's own routes all pass.
 */
final class RouteRegistrarTest extends WP_UnitTestCase {

	public function testRefusesEndpointsWithoutValidMetadata(): void {
		$registrar = new WordPressRouteRegistrar( new FailClosedAccessGate() );
		$callback  = static fn(): array => array();

		$registrar->add(
			'/probe-a',
			array(
				array(
					'methods'  => 'GET',
					'callback' => $callback,
				),
			)
		);
		$registrar->add(
			'/probe-b',
			array(
				array(
					'methods'  => 'GET',
					'callback' => $callback,
					'mercatus' => array( 'access' => 'everyone' ),
				),
			)
		);
		$registrar->add(
			'/probe-c',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => $callback,
					'permission_callback' => '__return_true',
					'mercatus'            => array( 'access' => 'public' ),
				),
			)
		);
		$registrar->add(
			'/probe-d',
			array(
				array(
					'methods'  => 'GET',
					'mercatus' => array( 'access' => 'public' ),
				),
			)
		);
		$registrar->add(
			'probe-e',
			array(
				array(
					'methods'  => 'GET',
					'callback' => $callback,
					'mercatus' => array( 'access' => 'public' ),
				),
			)
		);

		$this->assertSame( array( 'GET /probe-a', 'GET /probe-b', 'GET /probe-c', 'GET /probe-d', '? probe-e' ), $registrar->refused() );
	}

	public function testTheKernelsRoutesAreAllRegistered(): void {
		$kernel = Kernel::instance();
		$this->assertNotNull( $kernel );
		$routes = array_keys( rest_get_server()->get_routes( 'mercatus/v1' ) );

		$this->assertSame( array(), $kernel->container()->get( WordPressRouteRegistrar::class )->refused() );
		foreach ( array( '/mercatus/v1/features', '/mercatus/v1/health', '/mercatus/v1/system/cache/flush-group', '/mercatus/v1/modules' ) as $route ) {
			$this->assertContains( $route, $routes );
		}
	}
}
