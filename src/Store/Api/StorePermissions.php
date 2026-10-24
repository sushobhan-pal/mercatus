<?php
/**
 * core.store's named permissions (IDN-4).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Store\Api;

use Mercatus\Core\Contracts\Identity\PermissionDefinition;
use Mercatus\Core\Contracts\Identity\PermissionProvider;
use Mercatus\Core\Store\Application\StoreAccess;

/**
 * Labels are translated here, in the API layer (ARCH-7).
 */
final class StorePermissions implements PermissionProvider {

	public function permissions(): array {
		return array(
			new PermissionDefinition( StoreAccess::MANAGE, __( 'Edit and hide any store', 'mercatus' ) ),
		);
	}
}
