<?php
/**
 * `wp mercatus seed:scale` (KER-12).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Database\Seeders;

use Mercatus\Core\Kernel\Kernel;

/**
 * Refuses anywhere but a local or development site, and on a site that was seeded before: seeding adds data,
 * it never replaces it.
 */
final class ScaleSeedCommand {

	/**
	 * Creates the command.
	 *
	 * @param Kernel $kernel Booted kernel.
	 */
	public function __construct( private Kernel $kernel ) {}

	/**
	 * Builds the scale data set (PLAT-10) through each active module's seed step.
	 *
	 * ## OPTIONS
	 *
	 * [--scale=<fraction>]
	 * : Fraction of the full data set, for quicker local runs.
	 * ---
	 * default: 1
	 * ---
	 *
	 * [--seed=<number>]
	 * : Random seed; the same seed creates the same data.
	 *
	 * @param list<string>          $args       Positional arguments; none.
	 * @param array<string, string> $assoc_args Options.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		if ( ! in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) {
			\WP_CLI::error( 'Scale seeding runs only on a local or development site (WP_ENVIRONMENT_TYPE).' );
		}
		if ( false !== get_option( ScaleBenchmarkSeeder::OPTION ) ) {
			\WP_CLI::error( 'This site was seeded before; seed a fresh site.' );
		}
		$scale = (float) ( $assoc_args['scale'] ?? 1 );
		if ( $scale <= 0 || $scale > 1 ) {
			\WP_CLI::error( '--scale must be more than 0 and at most 1.' );
		}

		$started = microtime( true );
		$seeded  = ( new ScaleBenchmarkSeeder( $this->kernel->resolution()->activeIds() ) )->run(
			(int) ( $assoc_args['seed'] ?? ScaleBenchmarkSeeder::SEED ),
			$scale,
			static function ( string $message ): void {
				\WP_CLI::log( $message );
			}
		);

		\WP_CLI::success( sprintf( 'Seeded %s in %d s.', array() === $seeded ? 'no module data yet' : implode( ', ', $seeded ), (int) round( microtime( true ) - $started ) ) );
	}
}
