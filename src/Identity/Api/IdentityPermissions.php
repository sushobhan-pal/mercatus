<?php
/**
 * core.identity's named permissions (IDN-4, IDN-18).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Identity\Api;

use Mercatus\Core\Contracts\Identity\PermissionDefinition;
use Mercatus\Core\Contracts\Identity\PermissionProvider;

/**
 * Managing API clients; administrators always may.
 */
final class IdentityPermissions implements PermissionProvider {

	public const API_CLIENTS_MANAGE = 'identity.api_clients.manage';

	public function permissions(): array {
		return array(
			new PermissionDefinition( self::API_CLIENTS_MANAGE, __( 'Register and revoke API clients', 'mercatus' ) ),
		);
	}
}
