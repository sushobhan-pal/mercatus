<?php
/**
 * How the dashboard labels core.vendor's settings (VEN-16).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Vendor\Api;

use Mercatus\Core\Contracts\Settings\SettingsForm;
use Mercatus\Core\Vendor\Application\VendorSettings;

/**
 * Approval, the terms page, the application questions (VEN-13) and the dashboard page (VEN-15).
 */
final class VendorSettingsForm implements SettingsForm {

	public function title(): string {
		return __( 'Vendors', 'mercatus' );
	}

	public function fields(): array {
		return array(
			VendorSettings::AUTO_APPROVE       => array(
				'label' => __( 'Approve vendor applications automatically', 'mercatus' ),
			),
			VendorSettings::TERMS_PAGE         => array(
				'label'  => __( 'Terms applicants must accept', 'mercatus' ),
				'widget' => 'page',
			),
			VendorSettings::APPLICATION_FIELDS => array(
				'label'  => __( 'Extra application questions', 'mercatus' ),
				'help'   => __( 'Asked after the store name, phone and message. Answers are kept with each application as they were given.', 'mercatus' ),
				'widget' => 'questions',
			),
			VendorSettings::DASHBOARD_PAGE     => array(
				'label'  => __( 'Dashboard page', 'mercatus' ),
				'help'   => __( 'Mercatus shows the marketplace dashboard on this page, and on its translations, instead of the theme.', 'mercatus' ),
				'widget' => 'page',
			),
		);
	}
}
