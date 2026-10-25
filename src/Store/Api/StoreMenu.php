<?php
/**
 * core.store's dashboard menu items (ARCH-13).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Store\Api;

use Mercatus\Core\Contracts\Http\Access;
use Mercatus\Core\Contracts\Identity\DashboardMenuProvider;
use Mercatus\Core\Contracts\Identity\MenuItem;
use Mercatus\Core\Store\Application\StoreAccess;

/**
 * The vendor's own store, and every store for administrators and managers holding stores.manage.
 */
final class StoreMenu implements DashboardMenuProvider {

	public function items(): array {
		return array(
			new MenuItem( 'store', __( 'Store', 'mercatus' ), 'store', Access::Tenant, position: 12 ),
			new MenuItem( 'stores', __( 'Stores', 'mercatus' ), 'stores', Access::Admin, StoreAccess::MANAGE, position: 32 ),
		);
	}
}
