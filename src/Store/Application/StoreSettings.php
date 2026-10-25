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
 * Changed by administrators only.
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

	/**
	 * The first part of a store page's address, as in /store/{slug}/ (STO-10).
	 */
	public const BASE = 'core.store.base';

	/**
	 * The page showing the store list; 0 until Mercatus creates it (STO-10).
	 */
	public const STORE_LIST_PAGE = 'core.store.store_list_page';

	/**
	 * The geocoder that runs when a location's address changes (STO-14).
	 */
	public const GEOCODER = 'core.store.geocoder';

	/**
	 * Google's Geocoding API key, stored encrypted (SEC-6).
	 */
	public const GOOGLE_GEOCODING_KEY = 'core.store.google_geocoding_key';

	/**
	 * The geocoders a site may choose.
	 */
	public const GEOCODERS = array( 'none', 'nominatim', 'google' );

	public function definitions(): array {
		return array(
			new SettingDefinition( self::NEW_STORE_VISIBILITY, SettingType::Choice, Store::HIDDEN, Store::VISIBILITIES ),
			new SettingDefinition( self::VENDORS_CHANGE_SLUG, SettingType::Boolean, true ),
			new SettingDefinition( self::BASE, SettingType::Text, 'store' ),
			new SettingDefinition( self::STORE_LIST_PAGE, SettingType::Integer, 0, minimum: 0 ),
			new SettingDefinition( self::GEOCODER, SettingType::Choice, 'none', self::GEOCODERS ),
			new SettingDefinition( self::GOOGLE_GEOCODING_KEY, SettingType::Secret, '' ),
		);
	}
}
