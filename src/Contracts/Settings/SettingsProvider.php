<?php
/**
 * A module's settings (DRY-1, KER-4).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Contracts\Settings;

/**
 * Named by a module manifest's `settings` field. The registry asks only active modules, and every key must
 * start with the module's own ID.
 */
interface SettingsProvider {

	/**
	 * The module's settings.
	 *
	 * @return list<SettingDefinition>
	 */
	public function definitions(): array;
}
