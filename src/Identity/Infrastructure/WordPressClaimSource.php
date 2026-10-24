<?php
/**
 * Core's own claims (IDN-15).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Identity\Infrastructure;

use Mercatus\Core\Contracts\Identity\ActorClaim;
use Mercatus\Core\Contracts\Identity\ClaimSource;
use Mercatus\Core\Contracts\Settings\SettingsReader;
use Mercatus\Core\Identity\Application\IdentitySettings;

/**
 * Administrators from `manage_options`, managers from their appointment, and every signed-in user as a
 * customer. The only place Mercatus reads WordPress capabilities (G-SEC-1).
 */
final class WordPressClaimSource implements ClaimSource {

	/**
	 * User meta marking an appointed marketplace manager.
	 */
	public const MANAGER_META = 'mercatus_marketplace_manager';

	/**
	 * Creates the source.
	 *
	 * @param SettingsReader $settings Settings.
	 */
	public function __construct( private SettingsReader $settings ) {}

	public function claims( int $user_id ): array {
		$claims = array( ActorClaim::customer() );
		if ( user_can( $user_id, 'manage_options' ) ) {
			$claims[] = ActorClaim::administrator();
		}
		if ( '1' === get_user_meta( $user_id, self::MANAGER_META, true ) ) {
			$claims[] = ActorClaim::manager( IdentitySettings::managerPermissions( $this->settings ) );
		}

		return $claims;
	}
}
