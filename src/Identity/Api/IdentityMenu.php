<?php
/**
 * core.identity's dashboard menu items (ARCH-13, IDN-18).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Identity\Api;

use Mercatus\Core\Contracts\Http\Access;
use Mercatus\Core\Contracts\Identity\DashboardMenuProvider;
use Mercatus\Core\Contracts\Identity\MenuItem;

/**
 * API clients, for administrators and managers holding identity.api_clients.manage, and Managers, for
 * administrators only (VEN-8).
 */
final class IdentityMenu implements DashboardMenuProvider {

	public function items(): array {
		return array(
			new MenuItem( 'api-clients', __( 'API clients', 'mercatus' ), 'api-clients', Access::Admin, IdentityPermissions::API_CLIENTS_MANAGE, position: 80 ),
			new MenuItem( 'managers', __( 'Managers', 'mercatus' ), 'managers', Access::Admin, position: 85 ),
		);
	}
}
