<?php
/**
 * What a seed step works with (KER-12).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Database\Seeders;

use Closure;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Each step gets its own random sequence, derived from the run's seed and its module ID, so adding or changing
 * one module's step leaves every other module's data as it was.
 */
final class SeedContext {

	/**
	 * The PLAT-10 data set at full size.
	 */
	public const TARGETS = array(
		'vendors'    => 2000,
		'categories' => 5000,
		'attributes' => 2000,
		'products'   => 100000,
		'offers'     => 300000,
	);

	/**
	 * This step's random sequence.
	 */
	public readonly Randomizer $random;

	/**
	 * Creates the context.
	 *
	 * @param string                 $moduleId Module whose step this is.
	 * @param int                    $seed     The run's seed.
	 * @param float                  $scale    Fraction of the full data set, for quicker local runs.
	 * @param Closure(string): void  $log      Progress output.
	 * @param Closure(): void        $between  Runs between batches, to keep memory flat.
	 */
	public function __construct(
		public readonly string $moduleId,
		int $seed,
		private float $scale,
		private Closure $log,
		private Closure $between,
	) {
		$this->random = new Randomizer( new Mt19937( $seed ^ crc32( $moduleId ) ) );
	}

	/**
	 * How many of something to create at this run's scale, never fewer than one.
	 *
	 * @param key-of<self::TARGETS> $what Kind of record.
	 */
	public function count( string $what ): int {
		return max( 1, (int) round( self::TARGETS[ $what ] * $this->scale ) );
	}

	/**
	 * Works through a total in batches, reporting progress and freeing memory between them.
	 *
	 * @param int                       $total How many records to create.
	 * @param int                       $size  Records per batch.
	 * @param Closure(int, int): void   $work  Creates one batch, given its offset and size.
	 */
	public function inBatches( int $total, int $size, Closure $work ): void {
		for ( $offset = 0; $offset < $total; $offset += $size ) {
			if ( $offset > 0 ) {
				( $this->between )();
			}
			$work( $offset, min( $size, $total - $offset ) );
			$this->log( sprintf( '%s: %d of %d', $this->moduleId, min( $offset + $size, $total ), $total ) );
		}
	}

	/**
	 * Prints a progress line.
	 *
	 * @param string $message Message.
	 */
	public function log( string $message ): void {
		( $this->log )( $message );
	}
}
