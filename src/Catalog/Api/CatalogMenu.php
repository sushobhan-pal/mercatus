<?php
/**
 * core.catalog's dashboard menu items (ARCH-13).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Catalog\Api;

use Mercatus\Core\Catalog\Application\CatalogAccess;
use Mercatus\Core\Contracts\Http\Access;
use Mercatus\Core\Contracts\Identity\DashboardMenuProvider;
use Mercatus\Core\Contracts\Identity\MenuItem;

/**
 * Vendors manage their products; administrators, and managers holding products.moderate, moderate them.
 */
final class CatalogMenu implements DashboardMenuProvider {

	public function items(): array {
		return array(
			new MenuItem( 'products', __( 'Products', 'mercatus' ), 'products', Access::Tenant, position: 14 ),
			new MenuItem( 'moderation', __( 'Moderation', 'mercatus' ), 'moderation', Access::Admin, CatalogAccess::MODERATE, position: 34 ),
		);
	}
}
