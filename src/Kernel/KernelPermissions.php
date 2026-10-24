<?php
/**
 * The kernel's named permissions (IDN-4).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Kernel;

use Mercatus\Core\Contracts\Identity\PermissionDefinition;
use Mercatus\Core\Contracts\Identity\PermissionProvider;

/**
 * Administrators always pass; a marketplace manager needs the permission in the shared set (IDN-15).
 */
final class KernelPermissions implements PermissionProvider {

	public const HEALTH_VIEW    = 'kernel.health.view';
	public const CACHE_FLUSH    = 'kernel.cache.flush';
	public const MODULES_MANAGE = 'kernel.modules.manage';

	public function permissions(): array {
		return array(
			new PermissionDefinition( self::HEALTH_VIEW, __( 'View the health report', 'mercatus' ) ),
			new PermissionDefinition( self::CACHE_FLUSH, __( 'Clear cache groups', 'mercatus' ) ),
			new PermissionDefinition( self::MODULES_MANAGE, __( 'Switch optional modules on and off', 'mercatus' ) ),
		);
	}
}
