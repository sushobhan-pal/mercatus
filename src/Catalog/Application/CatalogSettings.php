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
 * Changed by an administrator only. Media and storefront settings join with their increments.
 */
final class CatalogSettings implements SettingsProvider {

	/**
	 * Whether a vendor's new product waits for review before it's published (CTL-20).
	 */
	public const REVIEW_NEW_PRODUCTS = 'core.catalog.review_new_products';

	public function definitions(): array {
		return array(
			new SettingDefinition( self::REVIEW_NEW_PRODUCTS, SettingType::Boolean, false ),
		);
	}
}
