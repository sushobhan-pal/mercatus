<?php
/**
 * The kernel's own settings (core.kernel §3).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Kernel;

use Mercatus\Core\Contracts\Settings\SettingDefinition;
use Mercatus\Core\Contracts\Settings\SettingsProvider;
use Mercatus\Core\Contracts\Settings\SettingType;

/**
 * Outbox and audit retention (KER-15) and uninstall behaviour (KER-16).
 */
final class KernelSettings implements SettingsProvider {

	public const OUTBOX_RETENTION_DAYS    = 'core.kernel.outbox_retention_days';
	public const AUDIT_PURGE_AFTER_YEARS  = 'core.kernel.audit_purge_after_years';
	public const REMOVE_DATA_ON_UNINSTALL = 'core.kernel.remove_data_on_uninstall';

	public function definitions(): array {
		return array(
			new SettingDefinition( self::OUTBOX_RETENTION_DAYS, SettingType::Integer, 7, minimum: 1, maximum: 365 ),
			// 0 keeps audit entries forever.
			new SettingDefinition( self::AUDIT_PURGE_AFTER_YEARS, SettingType::Integer, 0, minimum: 0, maximum: 100 ),
			new SettingDefinition( self::REMOVE_DATA_ON_UNINSTALL, SettingType::Boolean, false ),
		);
	}
}
