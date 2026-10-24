<?php
/**
 * core.vendor's named permissions (VEN-8).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Vendor\Api;

use Mercatus\Core\Contracts\Identity\PermissionDefinition;
use Mercatus\Core\Contracts\Identity\PermissionProvider;
use Mercatus\Core\Vendor\Application\VendorAccess;

/**
 * Labels are translated here, in the API layer (ARCH-7).
 */
final class VendorPermissions implements PermissionProvider {

	public function permissions(): array {
		return array(
			new PermissionDefinition( VendorAccess::REVIEW, __( 'Review vendor applications', 'mercatus' ) ),
			new PermissionDefinition( VendorAccess::MANAGE, __( 'Suspend and reinstate vendors', 'mercatus' ) ),
		);
	}
}
