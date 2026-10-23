<?php
/**
 * The current time (DRY-2, KER-10).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Shared\Clock;

use DateTimeImmutable;

/**
 * Code asks a Clock for the time instead of calling time(), so tests can freeze and move it.
 */
interface Clock {

	/**
	 * The current time, in UTC.
	 */
	public function now(): DateTimeImmutable;
}
