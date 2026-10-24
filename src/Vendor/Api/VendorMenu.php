<?php
/**
 * core.vendor's dashboard menu items (ARCH-13).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Vendor\Api;

use Mercatus\Core\Contracts\Http\Access;
use Mercatus\Core\Contracts\Identity\ActorType;
use Mercatus\Core\Contracts\Identity\DashboardMenuProvider;
use Mercatus\Core\Contracts\Identity\MenuItem;
use Mercatus\Core\Vendor\Application\VendorAccess;

/**
 * The applicant's or vendor's own account (applying, the application's progress, the vendor's standing), the
 * review queue and the vendor list.
 */
final class VendorMenu implements DashboardMenuProvider {

	public function items(): array {
		return array(
			new MenuItem( 'vendor-account', __( 'Selling', 'mercatus' ), 'vendor-account', Access::Authenticated, position: 10, actorTypes: array( ActorType::Customer, ActorType::VendorOwner ) ),
			new MenuItem( 'vendor-applications', __( 'Applications', 'mercatus' ), 'vendor-applications', Access::Admin, VendorAccess::REVIEW, position: 20 ),
			new MenuItem( 'vendors', __( 'Vendors', 'mercatus' ), 'vendors', Access::Admin, VendorAccess::MANAGE, position: 30 ),
		);
	}
}
