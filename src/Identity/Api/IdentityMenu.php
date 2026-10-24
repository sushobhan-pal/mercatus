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
 * The API clients screen, built with the dashboard shell in core.vendor.
 */
final class IdentityMenu implements DashboardMenuProvider {

	public function items(): array {
		return array(
			new MenuItem( 'api-clients', __( 'API clients', 'mercatus' ), 'api-clients', Access::Admin, IdentityPermissions::API_CLIENTS_MANAGE, position: 80 ),
		);
	}
}
