<?php
/**
 * A module the kernel knows about (ARCH-6, KER-14).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Kernel\Modules;

use Mercatus\Core\Contracts\Module\ModuleManifest;

/**
 * A manifest plus where it came from. Core decides which of its own modules are optional; every module an
 * add-on registers is optional (KER-14).
 */
final readonly class RegisteredModule {

	/**
	 * Creates the entry.
	 *
	 * @param ModuleManifest $manifest Manifest.
	 * @param bool           $optional Whether the admin can switch it off.
	 * @param bool           $fromCore Whether Mercatus itself ships it.
	 */
	public function __construct( public ModuleManifest $manifest, public bool $optional, public bool $fromCore ) {}

	/**
	 * Whether the module is on when the admin hasn't chosen: optional Free modules start on, add-on modules
	 * start off (KER-14).
	 */
	public function onByDefault(): bool {
		return ! $this->optional || $this->fromCore;
	}
}
