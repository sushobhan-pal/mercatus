<?php
/**
 * Query counts and latency of a scale run (PLAT-10).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Scale\Support;

use Mercatus\Core\Database\Seeders\ScaleBenchmarkSeeder;

/**
 * Writes artifacts/scale/baseline-<cache>.json for trends and a Markdown table for the CI job summary. Latency
 * is tracked, not gated: it measures Mercatus's handler time, without WordPress's and WooCommerce's bootstrap.
 */
final class ScaleReport {

	/**
	 * One scenario's result.
	 *
	 * @param ScaleScenario $scenario Scenario.
	 * @param int           $queries  Mercatus's queries in one run.
	 * @param list<float>   $timings  Milliseconds per run.
	 * @return array{id: string, ceiling: int, queries: int, median_ms: float, p95_ms: float}
	 */
	public static function row( ScaleScenario $scenario, int $queries, array $timings ): array {
		sort( $timings );
		$count = count( $timings );

		return array(
			'id'        => $scenario->id,
			'ceiling'   => $scenario->ceiling,
			'queries'   => $queries,
			'median_ms' => round( $timings[ intdiv( $count, 2 ) ] ?? 0.0, 3 ),
			'p95_ms'    => round( $timings[ max( 0, (int) ceil( 0.95 * $count ) - 1 ) ] ?? 0.0, 3 ),
		);
	}

	/**
	 * Writes the report files.
	 *
	 * @param list<array{id: string, ceiling: int, queries: int, median_ms: float, p95_ms: float}> $rows Results.
	 */
	public static function write( array $rows ): void {
		$cache = (string) getenv( 'CACHE_MODE' );
		$cache = '' === $cache ? ( wp_using_ext_object_cache() ? 'persistent' : 'none' ) : $cache;
		$dir   = dirname( __DIR__, 3 ) . '/artifacts/scale';
		wp_mkdir_p( $dir );

		$report = array(
			'cache'       => $cache,
			'persistent'  => (bool) wp_using_ext_object_cache(),
			'php'         => PHP_VERSION,
			'wordpress'   => get_bloginfo( 'version' ),
			'woocommerce' => defined( 'WC_VERSION' ) ? WC_VERSION : '',
			'seed'        => get_option( ScaleBenchmarkSeeder::OPTION ),
			'scenarios'   => $rows,
		);
		file_put_contents( $dir . '/baseline-' . $cache . '.json', (string) wp_json_encode( $report, JSON_PRETTY_PRINT ) . "\n" );

		$lines = array( '### Scale baseline (' . $cache . ' cache)', '', '| Scenario | Queries | Ceiling | Median ms | p95 ms |', '| --- | ---: | ---: | ---: | ---: |' );
		foreach ( $rows as $row ) {
			$lines[] = sprintf( '| %s | %d | %d | %.3f | %.3f |', $row['id'], $row['queries'], $row['ceiling'], $row['median_ms'], $row['p95_ms'] );
		}
		file_put_contents( $dir . '/baseline-' . $cache . '.md', implode( "\n", $lines ) . "\n\n" );
	}
}
