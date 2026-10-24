<?php
/**
 * A module's named permissions (ARCH-6).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Contracts\Identity;

/**
 * The class a manifest's `permissions` names. Names are unique across active modules; a duplicate is refused
 * and reported by the health check.
 */
interface PermissionProvider {

	/**
	 * The permissions.
	 *
	 * @return list<PermissionDefinition>
	 */
	public function permissions(): array;
}
