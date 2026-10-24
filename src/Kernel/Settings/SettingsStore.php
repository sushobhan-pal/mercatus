<?php
/**
 * Where setting values are kept (KER-4).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Kernel\Settings;

/**
 * One record per module. Only values the admin has set are stored; defaults come from the definitions.
 */
interface SettingsStore {

	/**
	 * A module's stored values, by key.
	 *
	 * @param string $module_id Module ID.
	 * @return array<string, mixed>
	 */
	public function load( string $module_id ): array;

	/**
	 * Replaces a module's stored values.
	 *
	 * @param string               $module_id Module ID.
	 * @param array<string, mixed> $values    Values by key, including any whose settings aren't registered now.
	 */
	public function save( string $module_id, array $values ): void;
}
