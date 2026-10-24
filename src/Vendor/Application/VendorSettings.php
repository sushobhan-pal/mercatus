<?php
/**
 * core.vendor's settings (§3).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Vendor\Application;

use Mercatus\Core\Contracts\Settings\SettingDefinition;
use Mercatus\Core\Contracts\Settings\SettingsProvider;
use Mercatus\Core\Contracts\Settings\SettingType;

/**
 * Changed by administrators only. The dashboard page arrives with increment 2.
 */
final class VendorSettings implements SettingsProvider {

	/**
	 * Approve applications automatically (VEN-3).
	 */
	public const AUTO_APPROVE = 'core.vendor.auto_approve';

	/**
	 * The page applicants must accept; 0 for none.
	 */
	public const TERMS_PAGE = 'core.vendor.terms_page';

	/**
	 * The administrator's extra questions, as JSON (VEN-13).
	 */
	public const APPLICATION_FIELDS = 'core.vendor.application_fields';

	public function definitions(): array {
		return array(
			new SettingDefinition( self::AUTO_APPROVE, SettingType::Boolean, false ),
			new SettingDefinition( self::TERMS_PAGE, SettingType::Integer, 0, minimum: 0 ),
			new SettingDefinition( self::APPLICATION_FIELDS, SettingType::Text, '' ),
		);
	}
}
