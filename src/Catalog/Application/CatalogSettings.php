<?php
/**
 * core.catalog's settings (P-1, CTL-20).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Catalog\Application;

use Mercatus\Core\Contracts\Settings\SettingDefinition;
use Mercatus\Core\Contracts\Settings\SettingsProvider;
use Mercatus\Core\Contracts\Settings\SettingType;

/**
 * Changed by an administrator only. Storefront settings join with increment 3.
 */
final class CatalogSettings implements SettingsProvider {

	/**
	 * Whether a vendor's new product waits for review before it's published (CTL-20).
	 */
	public const REVIEW_NEW_PRODUCTS = 'core.catalog.review_new_products';

	/**
	 * The largest image a vendor may upload, in MB (CTL-24).
	 */
	public const UPLOAD_MAX_MB = 'core.catalog.upload_max_mb';

	/**
	 * Each vendor's media space in MB, generated sizes included (CTL-24).
	 */
	public const VENDOR_MEDIA_QUOTA_MB = 'core.catalog.vendor_media_quota_mb';

	public function definitions(): array {
		return array(
			new SettingDefinition( self::REVIEW_NEW_PRODUCTS, SettingType::Boolean, false ),
			new SettingDefinition( self::UPLOAD_MAX_MB, SettingType::Integer, 5, minimum: 1, maximum: 100 ),
			new SettingDefinition( self::VENDOR_MEDIA_QUOTA_MB, SettingType::Integer, 500, minimum: 1, maximum: 1000000 ),
		);
	}
}
