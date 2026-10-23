<?php
/**
 * The real clock (KER-10).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Shared\Clock;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The only place Mercatus reads the system time.
 */
final class SystemClock implements Clock {

	public function now(): DateTimeImmutable {
		return new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
	}
}
