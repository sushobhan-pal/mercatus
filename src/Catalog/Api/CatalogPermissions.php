<?php
/**
 * core.catalog's named permissions (IDN-4).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Catalog\Api;

use Mercatus\Core\Catalog\Application\CatalogAccess;
use Mercatus\Core\Contracts\Identity\PermissionDefinition;
use Mercatus\Core\Contracts\Identity\PermissionProvider;

/**
 * Labels are translated here, in the API layer (ARCH-7).
 */
final class CatalogPermissions implements PermissionProvider {

	public function permissions(): array {
		return array(
			new PermissionDefinition( CatalogAccess::MODERATE, __( 'Approve, reject and take down vendors’ products', 'mercatus' ) ),
		);
	}
}
