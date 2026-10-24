<?php
/**
 * The kernel's dashboard menu items (ARCH-13, KER-14).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Kernel;

use Mercatus\Core\Contracts\Http\Access;
use Mercatus\Core\Contracts\Identity\DashboardMenuProvider;
use Mercatus\Core\Contracts\Identity\MenuItem;

/**
 * Modules, for administrators and managers holding kernel.modules.manage, and Settings, for administrators only
 * (VEN-8).
 */
final class KernelMenu implements DashboardMenuProvider {

	public function items(): array {
		return array(
			new MenuItem( 'modules', __( 'Modules', 'mercatus' ), 'modules', Access::Admin, KernelPermissions::MODULES_MANAGE, position: 90 ),
			new MenuItem( 'settings', __( 'Settings', 'mercatus' ), 'settings', Access::Admin, position: 95 ),
		);
	}
}
