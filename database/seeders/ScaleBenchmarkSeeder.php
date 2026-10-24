<?php
/**
 * The scale data set (PLAT-10, KER-12).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Database\Seeders;

use Closure;
use UnexpectedValueException;

/**
 * Runs the step of each active module that has one, in load order, with a fixed seed, so every run creates the
 * same data. It grows module by module; the kernel has no step of its own. Development tooling, left out of the
 * plugin zip (GW-10).
 */
final class ScaleBenchmarkSeeder {

	public const SEED = 1729;

	/**
	 * Records how the site was seeded; the scale suite refuses a site without it.
	 */
	public const OPTION = 'mercatus_scale_seed';

	/**
	 * Creates the seeder.
	 *
	 * @param list<string> $modules   Active module IDs, in load order.
	 * @param string       $directory Folder of step files, one per module, named after its ID.
	 */
	public function __construct( private array $modules, private string $directory = __DIR__ . '/steps' ) {}

	/**
	 * Runs the steps and records the run.
	 *
	 * @param int                   $seed  Random seed.
	 * @param float                 $scale Fraction of the full data set.
	 * @param Closure(string): void $log   Progress output.
	 * @return list<string> Modules whose step ran.
	 */
	public function run( int $seed, float $scale, Closure $log ): array {
		$seeded = array();
		wp_defer_term_counting( true );
		wp_defer_comment_counting( true );
		try {
			foreach ( $this->modules as $module_id ) {
				$step = $this->step( $module_id );
				if ( null !== $step ) {
					$log( $module_id );
					$step->run( new SeedContext( $module_id, $seed, $scale, $log, self::betweenBatches( ... ) ) );
					$seeded[] = $module_id;
				}
			}
		} finally {
			wp_defer_term_counting( false );
			wp_defer_comment_counting( false );
		}

		update_option(
			self::OPTION,
			array(
				'seed'      => $seed,
				'scale'     => $scale,
				'modules'   => $seeded,
				'seeded_at' => gmdate( 'c' ),
			),
			false
		);

		return $seeded;
	}

	/**
	 * A module's step, if it has one.
	 *
	 * @param string $module_id Module ID.
	 * @throws UnexpectedValueException When the file returns something else.
	 */
	private function step( string $module_id ): ?SeedStep {
		$file = $this->directory . '/' . $module_id . '.php';
		if ( ! is_file( $file ) ) {
			return null;
		}
		$step = require $file;
		if ( ! $step instanceof SeedStep ) {
			throw new UnexpectedValueException( $file . ' must return a SeedStep.' );
		}

		return $step;
	}

	/**
	 * Drops WordPress's in-request caches and query log, which otherwise grow with every record created.
	 */
	private static function betweenBatches(): void {
		global $wpdb;
		if ( wp_cache_supports( 'flush_runtime' ) ) {
			wp_cache_flush_runtime();
		}
		$wpdb->queries = array();
	}
}
