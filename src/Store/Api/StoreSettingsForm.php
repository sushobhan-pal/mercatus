<?php
/**
 * How the dashboard labels core.store's settings (VEN-16).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Store\Api;

use Mercatus\Core\Contracts\Settings\SettingsForm;
use Mercatus\Core\Store\Application\StoreSettings;

/**
 * New stores' visibility (STO-13), slug changes (STO-9), the store page's address and the store list page
 * (STO-10), and geocoding (STO-14).
 */
final class StoreSettingsForm implements SettingsForm {

	public function title(): string {
		return __( 'Stores', 'mercatus' );
	}

	public function fields(): array {
		return array(
			StoreSettings::NEW_STORE_VISIBILITY => array(
				'label' => __( 'New stores start', 'mercatus' ),
				'help'  => __( 'Hidden stores stay off the store list until their vendor opens them.', 'mercatus' ),
			),
			StoreSettings::VENDORS_CHANGE_SLUG  => array(
				'label' => __( 'Vendors may change their store’s address', 'mercatus' ),
				'help'  => __( 'The old address stops working when a vendor changes it.', 'mercatus' ),
			),
			StoreSettings::BASE                 => array(
				'label' => __( 'Store page address', 'mercatus' ),
				'help'  => __( 'The first part of every store page’s address, as in /store/rare-maps/. Lower-case letters, digits and hyphens.', 'mercatus' ),
			),
			StoreSettings::STORE_LIST_PAGE      => array(
				'label'  => __( 'Store list page', 'mercatus' ),
				'help'   => __( 'The page with the Stores block. Mercatus creates one; you may choose another.', 'mercatus' ),
				'widget' => 'page',
			),
			StoreSettings::GEOCODER             => array(
				'label' => __( 'Find coordinates for store addresses with', 'mercatus' ),
				'help'  => __( 'none, nominatim (OpenStreetMap, no key) or google (needs the key below). Only runs when an address changes.', 'mercatus' ),
			),
			StoreSettings::GOOGLE_GEOCODING_KEY => array(
				'label' => __( 'Google Geocoding API key', 'mercatus' ),
				'help'  => __( 'Stored encrypted. Needs MERCATUS_ENCRYPTION_KEY in wp-config.php.', 'mercatus' ),
			),
		);
	}
}
