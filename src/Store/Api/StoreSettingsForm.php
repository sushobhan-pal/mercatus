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
 * New stores' visibility (STO-13) and slug changes (STO-9).
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
		);
	}
}
