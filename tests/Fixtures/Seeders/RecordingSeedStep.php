<?php
/**
 * A seed step that records its runs.
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Fixtures\Seeders;

use Mercatus\Core\Database\Seeders\SeedContext;
use Mercatus\Core\Database\Seeders\SeedStep;

/**
 * Remembers which module's step ran and the first number its sequence gave.
 */
final class RecordingSeedStep implements SeedStep {

	/**
	 * First random number by module ID, in the order the steps ran.
	 *
	 * @var array<string, int>
	 */
	public static array $runs = array();

	public function run( SeedContext $context ): void {
		self::$runs[ $context->moduleId ] = $context->random->getInt( 0, PHP_INT_MAX );
	}
}
