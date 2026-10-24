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
 * Changed by administrators only (§3).
 */
final class IdentitySettings implements SettingsProvider {

	/**
	 * The permissions every marketplace manager holds, comma-separated. The settings form offers them as
	 * checkboxes from the permission catalog.
	 */
	public const MANAGER_PERMISSIONS = 'core.identity.manager_permissions';

	public const IDEMPOTENCY_RETENTION_HOURS = 'core.identity.idempotency_retention_hours';

	public const RATE_LIMIT_VISITOR_READS  = 'core.identity.rate_limit_visitor_reads';
	public const RATE_LIMIT_VISITOR_WRITES = 'core.identity.rate_limit_visitor_writes';
	public const RATE_LIMIT_USER_READS     = 'core.identity.rate_limit_user_reads';
	public const RATE_LIMIT_USER_WRITES    = 'core.identity.rate_limit_user_writes';
	public const RATE_LIMIT_CLIENT_READS   = 'core.identity.rate_limit_client_reads';
	public const RATE_LIMIT_CLIENT_WRITES  = 'core.identity.rate_limit_client_writes';

	/**
	 * The header a trusted proxy or CDN puts the visitor's IP address in; empty to use the connection's.
	 */
	public const TRUSTED_PROXY_HEADER = 'core.identity.trusted_proxy_header';

	public function definitions(): array {
		$limit = static fn( string $key, int $per_minute ): SettingDefinition => new SettingDefinition( $key, SettingType::Integer, $per_minute, minimum: 1, maximum: 1000000 );

		return array(
			new SettingDefinition( self::MANAGER_PERMISSIONS, SettingType::Text, '' ),
			new SettingDefinition( self::IDEMPOTENCY_RETENTION_HOURS, SettingType::Integer, 24, minimum: 1, maximum: 720 ),
			$limit( self::RATE_LIMIT_VISITOR_READS, 120 ),
			$limit( self::RATE_LIMIT_VISITOR_WRITES, 20 ),
			$limit( self::RATE_LIMIT_USER_READS, 600 ),
			$limit( self::RATE_LIMIT_USER_WRITES, 120 ),
			$limit( self::RATE_LIMIT_CLIENT_READS, 1200 ),
			$limit( self::RATE_LIMIT_CLIENT_WRITES, 300 ),
			new SettingDefinition( self::TRUSTED_PROXY_HEADER, SettingType::Text, '' ),
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
