<?php
/**
 * core.identity's settings (§3, IDN-15).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Identity\Application;

use Mercatus\Core\Contracts\Settings\SettingDefinition;
use Mercatus\Core\Contracts\Settings\SettingsProvider;
use Mercatus\Core\Contracts\Settings\SettingsReader;
use Mercatus\Core\Contracts\Settings\SettingType;

/**
 * Ticket 10's settings; the idempotency and rate-limit settings arrive with ticket 11.
 */
final class IdentitySettings implements SettingsProvider {

	/**
	 * The permissions every marketplace manager holds, comma-separated. The settings form offers them as
	 * checkboxes from the permission catalog.
	 */
	public const MANAGER_PERMISSIONS = 'core.identity.manager_permissions';

	public function definitions(): array {
		return array(
			new SettingDefinition( self::MANAGER_PERMISSIONS, SettingType::Text, '' ),
		);
	}

	/**
	 * The managers' shared permissions.
	 *
	 * @param SettingsReader $settings Settings.
	 * @return list<string>
	 */
	public static function managerPermissions( SettingsReader $settings ): array {
		$names = array_map( 'trim', explode( ',', $settings->string( self::MANAGER_PERMISSIONS ) ) );

		return array_values( array_unique( array_filter( $names, static fn( string $name ): bool => '' !== $name ) ) );
	}
}
