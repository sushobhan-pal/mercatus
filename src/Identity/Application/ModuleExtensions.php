<?php
/**
 * What active modules add to identity (IDN-3, ARCH-6).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Identity\Application;

use Closure;
use Mercatus\Core\Contracts\Identity\ClaimSource;
use Mercatus\Core\Contracts\Identity\DashboardMenuProvider;
use Mercatus\Core\Contracts\Identity\PermissionProvider;
use Mercatus\Core\Contracts\Module\ModuleManifest;

/**
 * The claim sources, permission providers and menu providers of the active modules, in load order. The
 * kernel binds it once the modules are resolved; each class is built on first use.
 */
final class ModuleExtensions {

	/**
	 * Claim sources, once built.
	 *
	 * @var list<ClaimSource>|null
	 */
	private ?array $claimSources = null;

	/**
	 * Creates the extensions.
	 *
	 * @param list<ModuleManifest>          $active Active modules, in load order.
	 * @param Closure(class-string): object $build  Builds a module's class.
	 */
	public function __construct( private array $active, private Closure $build ) {}

	/**
	 * The claim sources.
	 *
	 * @return list<ClaimSource>
	 */
	public function claimSources(): array {
		if ( null === $this->claimSources ) {
			$sources = array();
			foreach ( $this->active as $manifest ) {
				foreach ( $manifest->claimSources as $class_name ) {
					$source = ( $this->build )( $class_name );
					if ( $source instanceof ClaimSource ) {
						$sources[] = $source;
					}
				}
			}
			$this->claimSources = $sources;
		}

		return $this->claimSources;
	}

	/**
	 * The permission providers, by module ID.
	 *
	 * @return array<string, PermissionProvider>
	 */
	public function permissionProviders(): array {
		$providers = array();
		foreach ( $this->active as $manifest ) {
			$provider = null === $manifest->permissions ? null : ( $this->build )( $manifest->permissions );
			if ( $provider instanceof PermissionProvider ) {
				$providers[ $manifest->id ] = $provider;
			}
		}

		return $providers;
	}

	/**
	 * The dashboard menu providers.
	 *
	 * @return list<DashboardMenuProvider>
	 */
	public function menuProviders(): array {
		$providers = array();
		foreach ( $this->active as $manifest ) {
			$provider = null === $manifest->dashboardMenu ? null : ( $this->build )( $manifest->dashboardMenu );
			if ( $provider instanceof DashboardMenuProvider ) {
				$providers[] = $provider;
			}
		}

		return $providers;
	}
}
