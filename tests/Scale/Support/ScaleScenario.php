<?php
/**
 * A request path the scale gate measures (PLAT-10).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Scale\Support;

use Closure;

/**
 * What one request does, and the most queries Mercatus may run for it, with or without a persistent cache.
 */
final class ScaleScenario {

	/**
	 * Creates the scenario.
	 *
	 * @param string          $id      Stable name, used in the report.
	 * @param int             $ceiling Most queries of Mercatus's own.
	 * @param Closure(): mixed $work    What the request does.
	 */
	public function __construct( public readonly string $id, public readonly int $ceiling, public readonly Closure $work ) {}
}
