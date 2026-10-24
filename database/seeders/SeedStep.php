<?php
/**
 * One module's slice of the scale data set (KER-12).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Database\Seeders;

/**
 * A module adds its step as `database/seeders/steps/<module-id>.php`, a file that returns an instance. Steps
 * run in module load order, so a step can use what its dependencies' steps created.
 */
interface SeedStep {

	/**
	 * Creates the module's share of the data set.
	 *
	 * @param SeedContext $context Randomness, sizes and batching for this step.
	 */
	public function run( SeedContext $context ): void;
}
