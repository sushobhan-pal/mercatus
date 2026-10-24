<?php
/**
 * Reads modules' settings (DRY-1).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Contracts\Settings;

/**
 * Typed reads from the settings registry, for modules and Pro. Values are validated on write and fall back to
 * the default when a stored value no longer fits.
 */
interface SettingsReader {

	/**
	 * A Boolean setting.
	 *
	 * @param string $key Setting key.
	 */
	public function bool( string $key ): bool;

	/**
	 * An Integer setting.
	 *
	 * @param string $key Setting key.
	 */
	public function int( string $key ): int;

	/**
	 * A Text, Choice or Decimal setting.
	 *
	 * @param string $key Setting key.
	 */
	public function string( string $key ): string;
}
