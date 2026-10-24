<?php
/**
 * How the dashboard labels the kernel's settings (VEN-16).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Kernel;

use Mercatus\Core\Contracts\Settings\SettingsForm;

/**
 * Retention and uninstall (core.kernel §3).
 */
final class KernelSettingsForm implements SettingsForm {

	public function title(): string {
		return __( 'General', 'mercatus' );
	}

	public function fields(): array {
		return array(
			KernelSettings::OUTBOX_RETENTION_DAYS    => array(
				'label' => __( 'Keep processed events for (days)', 'mercatus' ),
			),
			KernelSettings::AUDIT_PURGE_AFTER_YEARS  => array(
				'label' => __( 'Delete audit entries after (years)', 'mercatus' ),
				'help'  => __( '0 keeps them forever.', 'mercatus' ),
			),
			KernelSettings::REMOVE_DATA_ON_UNINSTALL => array(
				'label' => __( 'Remove all Mercatus data when the plugin is deleted', 'mercatus' ),
			),
		);
	}
}
