<?php
/**
 * A module's WordPress and WooCommerce hooks (ARCH-6).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Contracts\Module;

/**
 * The one place a module attaches hooks. The kernel calls it only while the module is active, which is what
 * keeps a switched-off module free of hooks (zero footprint).
 */
interface ModuleHooks {

	/**
	 * Adds the module's actions and filters.
	 */
	public function register(): void;
}
