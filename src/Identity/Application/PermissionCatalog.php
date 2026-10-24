<?php
/**
 * Every named permission of the active modules (ARCH-6, IDN-4).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Identity\Application;

use Mercatus\Core\Contracts\Identity\PermissionDefinition;

/**
 * Built on first use. A name another module already declared is refused, kept by the earlier module in load
 * order, and reported by the health check.
 */
final class PermissionCatalog {

	/**
	 * Permissions by name, once built.
	 *
	 * @var array<string, PermissionDefinition>|null
	 */
	private ?array $permissions = null;

	/**
	 * Refused duplicates as "module: name".
	 *
	 * @var list<string>
	 */
	private array $refused = array();

	/**
	 * Creates the catalog.
	 *
	 * @param ModuleExtensions $modules Active modules' extensions.
	 */
	public function __construct( private ModuleExtensions $modules ) {}

	/**
	 * Whether a permission exists.
	 *
	 * @param string $name Permission name.
	 */
	public function knows( string $name ): bool {
		return isset( $this->all()[ $name ] );
	}

	/**
	 * Every permission, by name.
	 *
	 * @return array<string, PermissionDefinition>
	 */
	public function all(): array {
		if ( null === $this->permissions ) {
			$this->permissions = array();
			foreach ( $this->modules->permissionProviders() as $module_id => $provider ) {
				foreach ( $provider->permissions() as $permission ) {
					if ( isset( $this->permissions[ $permission->name ] ) ) {
						$this->refused[] = $module_id . ': ' . $permission->name;
						continue;
					}
					$this->permissions[ $permission->name ] = $permission;
				}
			}
		}

		return $this->permissions;
	}

	/**
	 * Duplicate permissions that were refused.
	 *
	 * @return list<string>
	 */
	public function refused(): array {
		$this->all();

		return $this->refused;
	}
}
