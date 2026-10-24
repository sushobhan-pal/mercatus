<?php
/**
 * Module manifest (ARCH-6).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Contracts\Module;

use InvalidArgumentException;

/**
 * What a module declares before it loads. This class checks each manifest's own shape; the kernel checks
 * manifests against each other (unique IDs, routes and permissions, dependency order, migration state).
 */
final readonly class ModuleManifest {

	private const ID_PATTERN = '/^(core|pro)\.[a-z][a-z0-9-]*$/';

	private const VERSION_PATTERN = '/^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/';

	/**
	 * Creates a manifest.
	 *
	 * @param string                $id               Module ID, core.<name> or pro.<name> (P-4).
	 * @param string                $version          Semantic version of the module.
	 * @param array<string, string> $requires         Version constraints by dependency: "core" or a module ID.
	 * @param list<string>          $provides         Feature names the module adds to /features.
	 * @param class-string|null     $routes           Registers the module's routes through the registrar (ARCH-8).
	 * @param class-string|null     $migrations       The module's migrations.
	 * @param class-string|null     $permissions      The module's named permissions.
	 * @param class-string|null     $dashboardMenu    The module's dashboard menu items and screens (ARCH-13).
	 * @param list<class-string>    $eventSubscribers Domain-event subscribers.
	 * @param list<class-string>    $healthChecks     Health checks reported by GET /health.
	 * @param class-string|null     $settings         Declares the module's settings (a `SettingsProvider`).
	 * @param class-string|null     $hooks            Attaches the module's WordPress hooks while it's active (`ModuleHooks`).
	 * @param list<class-string>    $claimSources     Tell the identity resolver who users are to the module (`ClaimSource`).
	 * @param list<class-string>    $meSections       Add sections to GET /me (`MeSectionProvider`).
	 * @param class-string|null     $settingsForm     Labels the module's settings on the dashboard (`SettingsForm`).
	 * @throws InvalidArgumentException When the ID, version or a requirement is malformed.
	 */
	public function __construct(
		public string $id,
		public string $version,
		public array $requires = array(),
		public array $provides = array(),
		public ?string $routes = null,
		public ?string $migrations = null,
		public ?string $permissions = null,
		public ?string $dashboardMenu = null,
		public array $eventSubscribers = array(),
		public array $healthChecks = array(),
		public ?string $settings = null,
		public ?string $hooks = null,
		public array $claimSources = array(),
		public array $meSections = array(),
		public ?string $settingsForm = null,
	) {
		if ( 1 !== preg_match( self::ID_PATTERN, $id ) ) {
			throw new InvalidArgumentException( 'A module ID is core.<name> or pro.<name>, lower case.' );
		}
		if ( 1 !== preg_match( self::VERSION_PATTERN, $version ) ) {
			throw new InvalidArgumentException( 'A module version is a semantic version such as 1.0.0.' );
		}
		foreach ( $requires as $dependency => $constraint ) {
			if ( ( 'core' !== $dependency && 1 !== preg_match( self::ID_PATTERN, $dependency ) ) || '' === trim( $constraint ) ) {
				throw new InvalidArgumentException( 'A requirement maps "core" or a module ID to a version constraint.' );
			}
		}
	}
}
