<?php
/**
 * Where the admin's module choices are kept (KER-14).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Kernel\Modules;

/**
 * On/off by module ID, for optional modules only. A module with no entry uses its default.
 */
interface ModuleStateStore {

	/**
	 * The admin's choices.
	 *
	 * @return array<string, bool>
	 */
	public function states(): array;

	/**
	 * Saves the admin's choices.
	 *
	 * @param array<string, bool> $states On/off by module ID.
	 */
	public function save( array $states ): void;
}
