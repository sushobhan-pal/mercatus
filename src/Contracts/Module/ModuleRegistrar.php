<?php
/**
 * How add-ons register their modules (ARCH-8 point 8, KER-13).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Contracts\Module;

/**
 * Passed to the `mercatus_register_modules` action, which Mercatus fires once while it boots. Add-ons, Pro
 * included, register every module they ship, active or not; the kernel decides which ones load.
 */
interface ModuleRegistrar {

	/**
	 * Registers a module.
	 *
	 * @param ModuleManifest $manifest The module's manifest.
	 */
	public function register( ModuleManifest $manifest ): void;
}
