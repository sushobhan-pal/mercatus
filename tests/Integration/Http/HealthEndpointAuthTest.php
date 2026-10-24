<?php
/**
 * GET /health is for administrators and permitted managers (TEST-3 row 1, TM-38, IDN-4).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Integration\Http;

use Mercatus\Core\Kernel\Health\HealthReport;
use Mercatus\Core\Kernel\Kernel;
use Mercatus\Core\Kernel\KernelPermissions;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Everyone else is refused; the report behind the route covers the kernel's and the modules' checks.
 */
final class HealthEndpointAuthTest extends WP_UnitTestCase {

	use AdminRouteAccess;

	public function testRefusesEveryoneElse(): void {
		$this->assertRefusesAllButPermitted( static fn(): WP_REST_Request => new WP_REST_Request( 'GET', '/mercatus/v1/health' ) );
	}

	public function testAllowsAdministratorsAndManagersWithThePermission(): void {
		$this->assertAllowsAdministratorsAndPermittedManagers( static fn(): WP_REST_Request => new WP_REST_Request( 'GET', '/mercatus/v1/health' ), KernelPermissions::HEALTH_VIEW );
	}

	public function testReportsTheKernelsAndTheModulesChecks(): void {
		$kernel = Kernel::instance();
		$this->assertNotNull( $kernel );

		$report = $kernel->container()->get( HealthReport::class )->build();
		$checks = array_column( $report['checks'], 'code', 'id' );

		$this->assertContains( $report['status'], array( 'ok', 'warning', 'error' ) );
		$this->assertSame( 'mercatus_migrations_current', $checks['kernel.migrations'] ?? null );
		$this->assertSame( 'mercatus_modules_loaded', $checks['kernel.modules'] ?? null );
		$this->assertSame( 'mercatus_routes_registered', $checks['kernel.routes'] ?? null );
		$this->assertSame( 'mercatus_permissions_unique', $checks['identity.permissions'] ?? null );
		$this->assertSame( wp_using_ext_object_cache() ? 'mercatus_persistent_cache' : 'mercatus_no_persistent_cache', $checks['kernel.cache'] ?? null );
	}
}
