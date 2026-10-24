<?php
/**
 * Which modules load, in what order, and why others don't (ARCH-6).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Kernel\Modules;

use Mercatus\Core\Contracts\Module\ModuleManifest;
use Mercatus\Core\Contracts\Settings\SettingDefinition;

/**
 * The validator's answer. Active modules are in dependency order: a module always comes after the modules it
 * requires.
 */
final readonly class ModuleResolution {

	/**
	 * Creates the resolution.
	 *
	 * @param list<ModuleManifest>                   $active   Modules to load, dependencies first.
	 * @param array<string, list<SettingDefinition>> $settings Settings of each active module, by module ID.
	 * @param list<string>                           $inactive IDs of optional modules that are switched off.
	 * @param array<string, string>                  $rejected Reason code by module ID, for modules that should load but can't.
	 */
	public function __construct(
		public array $active,
		public array $settings,
		public array $inactive,
		public array $rejected,
	) {}

	/**
	 * IDs of the active modules, in load order.
	 *
	 * @return list<string>
	 */
	public function activeIds(): array {
		return array_map( static fn( ModuleManifest $manifest ): string => $manifest->id, $this->active );
	}
}
