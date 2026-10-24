<?php
/**
 * core.store's settings (§3).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Store\Application;

use Mercatus\Core\Contracts\Settings\SettingDefinition;
use Mercatus\Core\Contracts\Settings\SettingsProvider;
use Mercatus\Core\Contracts\Settings\SettingType;
use Mercatus\Core\Store\Domain\Store;

/**
 * Changed by administrators only. The store page's address, the store list page and geocoding arrive with
 * increment 2.
 */
final class StoreSettings implements SettingsProvider {

	/**
	 * Whether a new store starts public or hidden until its vendor opens it (STO-13).
	 */
	public const NEW_STORE_VISIBILITY = 'core.store.new_store_visibility';

	/**
	 * Whether vendors may change their store's slug (STO-9).
	 */
	public const VENDORS_CHANGE_SLUG = 'core.store.vendors_change_slug';

	public function definitions(): array {
		return array(
			new SettingDefinition( self::NEW_STORE_VISIBILITY, SettingType::Choice, Store::HIDDEN, Store::VISIBILITIES ),
			new SettingDefinition( self::VENDORS_CHANGE_SLUG, SettingType::Boolean, true ),
		);
	}
}
