<?php
/**
 * Layer 1 architecture rules that Deptrac can't express (TEST-1).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Architecture;

use Mercatus\Core\Tests\Architecture\Support\ArchitectureScanner;
use PHPUnit\Framework\TestCase;

/**
 * Runs the scanner over src/ and proves each rule against deliberately broken fixtures.
 */
final class DependencyRulesTest extends TestCase {

	private const SRC_DIR = __DIR__ . '/../../src';

	private const TESTS_DIR = __DIR__ . '/..';

	private const FIXTURE_SRC_DIR = __DIR__ . '/Fixtures/src';

	private const FIXTURE_TESTS_DIR = __DIR__ . '/Fixtures/tests';

	/**
	 * Scanner under test.
	 *
	 * @var ArchitectureScanner
	 */
	private ArchitectureScanner $scanner;

	/**
	 * Creates a fresh scanner for each test.
	 */
	protected function setUp(): void {
		$this->scanner = new ArchitectureScanner();
	}

	/**
	 * Domain and Application stay free of request input, responses, output, translation and $wpdb;
	 * Api stays free of $wpdb (G-BND-1, G-BND-2).
	 */
	public function testSourceHasNoRuntimeLeaks(): void {
		$this->assertSame( array(), $this->scanner->runtimeLeaks( self::SRC_DIR ) );
	}

	/**
	 * Core never references Pro (G-BND-3).
	 */
	public function testSourceNeverReferencesPro(): void {
		$this->assertSame( array(), $this->scanner->proReferences( self::SRC_DIR ) );
	}

	/**
	 * Only WordPress's object cache talks to Redis or Memcached (PLAT-3).
	 */
	public function testSourceUsesNoCacheClients(): void {
		$this->assertSame( array(), $this->scanner->cacheClientReferences( self::SRC_DIR ) );
	}

	/**
	 * Only the outbound allow-list calls other hosts (SEC-7, TM-31).
	 */
	public function testSourceCallsOtherHostsOnlyThroughTheAllowList(): void {
		$this->assertSame( array(), $this->scanner->outboundCalls( self::SRC_DIR ) );
	}

	/**
	 * Every command handler and policy has a matching test file.
	 */
	public function testEveryHandlerAndPolicyHasATest(): void {
		$this->assertSame( array(), $this->scanner->untestedHandlersAndPolicies( self::SRC_DIR, self::TESTS_DIR ) );
	}

	/**
	 * The runtime-leak rule catches every kind of leak in the fixtures, including $wpdb in Api,
	 * and leaves the Application fixtures and Api's $_GET alone.
	 */
	public function testRuntimeLeakRuleCatchesFixtureViolations(): void {
		$this->assertSame(
			array(
				'Sample/Api/LeakyController.php:12 Api layer uses $wpdb',
				'Sample/Domain/LeakyEntity.php:12 Domain layer reads $_POST',
				'Sample/Domain/LeakyEntity.php:14 Domain layer calls translation function __()',
				'Sample/Domain/LeakyEntity.php:15 Domain layer calls wp_send_json_success()',
				'Sample/Domain/LeakyEntity.php:16 Domain layer uses $wpdb',
				'Sample/Domain/LeakyEntity.php:17 Domain layer uses $wpdb',
				'Sample/Domain/LeakyEntity.php:18 Domain layer uses $GLOBALS[\'wpdb\']',
				'Sample/Domain/LeakyEntity.php:19 Domain layer outputs directly',
			),
			$this->scanner->runtimeLeaks( self::FIXTURE_SRC_DIR )
		);
	}

	/**
	 * The Pro-reference rule catches both the import and the use of a Pro class.
	 */
	public function testProReferenceRuleCatchesFixtureViolations(): void {
		$this->assertSame(
			array(
				'Sample/Domain/LeakyEntity.php:6 references Pro class Mercatus\\Pro\\Payouts\\Payout',
				'Sample/Domain/LeakyEntity.php:20 references Pro class Mercatus\\Pro\\Payouts\\Payout',
			),
			$this->scanner->proReferences( self::FIXTURE_SRC_DIR )
		);
	}

	/**
	 * The cache-client rule catches Redis and Predis in the fixtures.
	 */
	public function testCacheClientRuleCatchesFixtureViolations(): void {
		$this->assertSame(
			array(
				'Sample/Domain/LeakyEntity.php:21 uses cache client Redis',
				'Sample/Domain/LeakyEntity.php:22 uses cache client Predis\\Client',
			),
			$this->scanner->cacheClientReferences( self::FIXTURE_SRC_DIR )
		);
	}

	/**
	 * The outbound rule catches a direct HTTP call in the fixtures.
	 */
	public function testOutboundRuleCatchesFixtureViolations(): void {
		$this->assertSame(
			array( 'Sample/Infrastructure/CallsOut.php:10 calls wp_remote_get() outside OutboundHttp' ),
			$this->scanner->outboundCalls( self::FIXTURE_SRC_DIR )
		);
	}

	/**
	 * The test-presence rule flags the untested handler and accepts the covered policy.
	 */
	public function testHandlerAndPolicyRuleCatchesFixtureViolation(): void {
		$this->assertSame(
			array( 'Sample/Application/UntestedHandler.php:6 UntestedHandler has no UntestedHandlerTest.php' ),
			$this->scanner->untestedHandlersAndPolicies( self::FIXTURE_SRC_DIR, self::FIXTURE_TESTS_DIR )
		);
	}
}
