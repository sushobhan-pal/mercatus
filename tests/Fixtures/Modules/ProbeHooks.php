<?php
/**
 * Hooks of the probe module used by kernel tests.
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Fixtures\Modules;

use Mercatus\Core\Contracts\Module\ModuleHooks;

/**
 * Adds one filter and counts how often it was built, so tests can prove a switched-off module never is.
 */
final class ProbeHooks implements ModuleHooks {

	public const FILTER = 'mercatus_probe_enabled';

	public static int $built = 0;

	public function __construct() {
		++self::$built;
	}

	public function register(): void {
		add_filter( self::FILTER, '__return_true' );
	}
}
