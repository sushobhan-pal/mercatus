<?php
/**
 * How the dashboard labels core.identity's settings (VEN-16).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Identity\Api;

use Mercatus\Core\Contracts\Settings\SettingsForm;
use Mercatus\Core\Identity\Application\IdentitySettings;

/**
 * The managers' shared permissions as a checklist (IDN-15), idempotency retention, rate limits (IDN-19) and the
 * trusted proxy header.
 */
final class IdentitySettingsForm implements SettingsForm {

	public function title(): string {
		return __( 'Access', 'mercatus' );
	}

	public function fields(): array {
		/* translators: %s: who the limit applies to, for example "Visitors". */
		$reads = static fn( string $who ): string => sprintf( __( '%s: reads per minute', 'mercatus' ), $who );
		/* translators: %s: who the limit applies to, for example "Visitors". */
		$writes = static fn( string $who ): string => sprintf( __( '%s: changes per minute', 'mercatus' ), $who );

		return array(
			IdentitySettings::MANAGER_PERMISSIONS         => array(
				'label'  => __( 'What marketplace managers may do', 'mercatus' ),
				'help'   => __( 'Every manager holds the same permissions. Settings and managers stay with administrators.', 'mercatus' ),
				'widget' => 'permissions',
			),
			IdentitySettings::IDEMPOTENCY_RETENTION_HOURS => array(
				'label' => __( 'Remember repeated requests for (hours)', 'mercatus' ),
			),
			IdentitySettings::RATE_LIMIT_VISITOR_READS    => array( 'label' => $reads( __( 'Visitors', 'mercatus' ) ) ),
			IdentitySettings::RATE_LIMIT_VISITOR_WRITES   => array( 'label' => $writes( __( 'Visitors', 'mercatus' ) ) ),
			IdentitySettings::RATE_LIMIT_USER_READS       => array( 'label' => $reads( __( 'Signed-in users', 'mercatus' ) ) ),
			IdentitySettings::RATE_LIMIT_USER_WRITES      => array( 'label' => $writes( __( 'Signed-in users', 'mercatus' ) ) ),
			IdentitySettings::RATE_LIMIT_CLIENT_READS     => array( 'label' => $reads( __( 'API clients', 'mercatus' ) ) ),
			IdentitySettings::RATE_LIMIT_CLIENT_WRITES    => array( 'label' => $writes( __( 'API clients', 'mercatus' ) ) ),
			IdentitySettings::TRUSTED_PROXY_HEADER        => array(
				'label' => __( 'Trusted proxy header', 'mercatus' ),
				'help'  => __( 'The header your proxy or CDN puts the visitor\'s IP address in, for example X-Forwarded-For. Leave empty without one.', 'mercatus' ),
			),
		);
	}
}
