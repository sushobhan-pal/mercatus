<?php
/**
 * core.vendor's dashboard menu items (ARCH-13).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Vendor\Api;

use Mercatus\Core\Contracts\Http\Access;
use Mercatus\Core\Contracts\Identity\DashboardMenuProvider;
use Mercatus\Core\Contracts\Identity\MenuItem;
use Mercatus\Core\Vendor\Application\VendorAccess;

/**
 * The review queue and the vendor list; their screens arrive with the dashboard shell.
 */
final class VendorMenu implements DashboardMenuProvider {

	public function items(): array {
		return array(
			new MenuItem( 'vendor-applications', __( 'Applications', 'mercatus' ), 'vendor-applications', Access::Admin, VendorAccess::REVIEW, position: 20 ),
			new MenuItem( 'vendors', __( 'Vendors', 'mercatus' ), 'vendors', Access::Admin, VendorAccess::MANAGE, position: 30 ),
		);
	}
}
