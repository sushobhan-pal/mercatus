<?php
/**
 * Query ceilings are hard gates on the seeded data set; latency is tracked (TEST-1 Layer 6, PLAT-10).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Scale;

use Closure;
use Mercatus\Core\Database\Seeders\ScaleBenchmarkSeeder;
use Mercatus\Core\Tests\Scale\Scenarios\IdentityScenarios;
use Mercatus\Core\Tests\Scale\Scenarios\KernelScenarios;
use Mercatus\Core\Tests\Scale\Scenarios\StoreScenarios;
use Mercatus\Core\Tests\Scale\Scenarios\VendorScenarios;
use Mercatus\Core\Tests\Scale\Support\ScaleReport;
use Mercatus\Core\Tests\Scale\Support\ScaleScenario;
use PHPUnit\Framework\TestCase;

/**
 * Each scenario runs once to warm up, as earlier requests would have, then once counting Mercatus's own
 * queries (those with Mercatus code on the call stack), then RUNS times for timing. Modules add their
 * scenarios to the provider as they arrive. The CI legs run it with and without a persistent cache.
 */
final class BaselineQueryAndLatencyGateTest extends TestCase {

	private const RUNS = 25;

	/**
	 * Results so far, written out after the last scenario.
	 *
	 * @var list<array{id: string, ceiling: int, queries: int, median_ms: float, p95_ms: float}>
	 */
	private static array $results = array();

	public static function setUpBeforeClass(): void {
		self::assertIsArray( get_option( ScaleBenchmarkSeeder::OPTION ), 'This site was not built by wp mercatus seed:scale; run bin/scale.sh.' );
	}

	public static function tearDownAfterClass(): void {
		ScaleReport::write( self::$results );
	}

	/**
	 * Scenarios by ID.
	 *
	 * @return array<string, array{ScaleScenario}>
	 */
	public static function scenarios(): array {
		$scenarios = array();
		foreach ( array_merge( KernelScenarios::all(), IdentityScenarios::all(), VendorScenarios::all(), StoreScenarios::all() ) as $scenario ) {
			$scenarios[ $scenario->id ] = array( $scenario );
		}

		return $scenarios;
	}

	/**
	 * Fails when a scenario runs more queries than its ceiling.
	 *
	 * @dataProvider scenarios
	 * @param ScaleScenario $scenario Scenario.
	 */
	public function testStaysWithinItsQueryCeiling( ScaleScenario $scenario ): void {
		( $scenario->work )();
		$queries = self::mercatusQueries( $scenario->work );

		$timings = array();
		for ( $run = 0; $run < self::RUNS; $run++ ) {
			$started = hrtime( true );
			( $scenario->work )();
			$timings[] = ( hrtime( true ) - $started ) / 1e6;
		}
		self::$results[] = ScaleReport::row( $scenario, count( $queries ), $timings );

		$this->assertLessThanOrEqual( $scenario->ceiling, count( $queries ), "Mercatus's queries:\n" . implode( "\n", $queries ) );
	}

	/**
	 * Runs work and returns the queries that had Mercatus code on their call stack.
	 *
	 * @param Closure(): mixed $work Work.
	 * @return list<string>
	 */
	private static function mercatusQueries( Closure $work ): array {
		global $wpdb;
		$start = count( $wpdb->queries );
		$work();

		$queries = array();
		foreach ( array_slice( $wpdb->queries, $start ) as $query ) {
			if ( str_contains( (string) $query[2], 'Mercatus\\' ) ) {
				$queries[] = (string) $query[0];
			}
		}

		return $queries;
	}
}
