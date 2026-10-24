<?php
/**
 * A module's dashboard menu items (ARCH-6, ARCH-13).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Contracts\Identity;

/**
 * The class a manifest's `dashboardMenu` names.
 */
interface DashboardMenuProvider {

	/**
	 * The items.
	 *
	 * @return list<MenuItem>
	 */
	public function items(): array;
}
