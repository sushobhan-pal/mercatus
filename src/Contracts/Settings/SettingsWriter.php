<?php
/**
 * Changing a setting from code (KER-4).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Contracts\Settings;

/**
 * For values a module sets itself, such as the dashboard page it creates (VEN-15). Administrators' changes go
 * through `PATCH /settings/{module}`, which also publishes and audits them.
 */
interface SettingsWriter {

	/**
	 * Changes a setting.
	 *
	 * @param string $key   Setting key.
	 * @param mixed  $value New value, valid for the setting.
	 * @throws \Mercatus\Core\Shared\Errors\DomainError When the setting isn't registered or the value doesn't fit it.
	 */
	public function set( string $key, mixed $value ): void;
}
