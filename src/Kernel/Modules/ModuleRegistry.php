<?php
/**
 * Every module the kernel knows about (ARCH-6, KER-13).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Kernel\Modules;

use Mercatus\Core\Contracts\Module\ModuleManifest;
use Mercatus\Core\Contracts\Module\ModuleRegistrar;

/**
 * Starts with Core's modules; add-ons add theirs through the `mercatus_register_modules` action. Everything an
 * add-on registers is optional (KER-14).
 */
final class ModuleRegistry implements ModuleRegistrar {

	/**
	 * Registered modules, Core's first.
	 *
	 * @var list<RegisteredModule>
	 */
	private array $modules;

	/**
	 * Creates the registry with Core's modules.
	 *
	 * @param list<RegisteredModule> $core Core's modules.
	 */
	public function __construct( array $core ) {
		$this->modules = $core;
	}

	public function register( ModuleManifest $manifest ): void {
		$this->modules[] = new RegisteredModule( $manifest, true, false );
	}

	/**
	 * Registered modules, Core's first.
	 *
	 * @return list<RegisteredModule>
	 */
	public function modules(): array {
		return $this->modules;
	}

	/**
	 * The first module registered under an ID.
	 *
	 * @param string $id Module ID.
	 */
	public function find( string $id ): ?RegisteredModule {
		foreach ( $this->modules as $module ) {
			if ( $module->manifest->id === $id ) {
				return $module;
			}
		}

		return null;
	}
}
