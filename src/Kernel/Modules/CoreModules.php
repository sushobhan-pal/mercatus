<?php
/**
 * The modules Mercatus itself ships (ROAD-3, KER-14).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Kernel\Modules;

use Mercatus\Core\Contracts\Module\ModuleManifest;
use Mercatus\Core\Kernel\KernelMigrations;
use Mercatus\Core\Kernel\KernelSettings;

/**
 * Each module joins this list when it's built. Only Reviews and Shipping will be optional (ARCH-6).
 */
final class CoreModules {

	/**
	 * Core's modules, in load order.
	 *
	 * @param string $version Mercatus version; Core modules share it.
	 * @return list<RegisteredModule>
	 */
	public static function all( string $version ): array {
		return array(
			new RegisteredModule( new ModuleManifest( id: 'core.kernel', version: $version, migrations: KernelMigrations::class, settings: KernelSettings::class ), false, true ),
		);
	}
}
