<?php
/**
 * The committed OpenAPI file matches the registered routes (TEST-1 Layer 3, TEST-3, DRY-1).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Contract;

use Mercatus\Core\Tests\Contract\Support\OpenApiGenerator;
use Mercatus\Core\Tests\Support\Routes\RouteOperations;
use WP_UnitTestCase;

/**
 * Snapshot test, run with the integration suite because it needs WordPress. With MERCATUS_UPDATE_OPENAPI=1
 * it rewrites the file first; `composer openapi:update` does that inside the Docker test environment.
 */
final class OpenApiSchemaConformanceTest extends WP_UnitTestCase {

	public function testCommittedSpecMatchesTheRegisteredRoutes(): void {
		$file      = dirname( __DIR__, 2 ) . '/openapi/mercatus-v1.yaml';
		$generated = OpenApiGenerator::yaml( OpenApiGenerator::generate( RouteOperations::fromServer( rest_get_server() ) ) );

		if ( '1' === getenv( 'MERCATUS_UPDATE_OPENAPI' ) ) {
			file_put_contents( $file, $generated );
		}

		$this->assertFileExists( $file );
		$this->assertSame( $generated, (string) file_get_contents( $file ), 'openapi/mercatus-v1.yaml is out of date: run composer openapi:update and commit it.' );
	}
}
