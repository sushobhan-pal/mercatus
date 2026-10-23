<?php
/**
 * How healthy something is (PLAT-4).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Contracts\Health;

/**
 * Ok, worth a look, or broken.
 */
enum HealthStatus: string {
	case Ok      = 'ok';
	case Warning = 'warning';
	case Error   = 'error';
}
