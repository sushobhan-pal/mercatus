<?php
/**
 * Checks manifests against each other before anything loads (ARCH-6, KER-2).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Kernel\Modules;

use Closure;
use InvalidArgumentException;
use Mercatus\Core\Contracts\Database\MigrationSource;
use Mercatus\Core\Contracts\Events\EventSubscriber;
use Mercatus\Core\Contracts\Health\HealthCheck;
use Mercatus\Core\Contracts\Http\RouteProvider;
use Mercatus\Core\Contracts\Identity\ClaimSource;
use Mercatus\Core\Contracts\Identity\DashboardMenuProvider;
use Mercatus\Core\Contracts\Identity\PermissionProvider;
use Mercatus\Core\Contracts\Module\ModuleHooks;
use Mercatus\Core\Contracts\Module\ModuleManifest;
use Mercatus\Core\Contracts\Settings\SettingDefinition;
use Mercatus\Core\Contracts\Settings\SettingsProvider;

/**
 * Fails closed: a module that can't load safely is rejected with a reason code, and so is every module that
 * requires it. Switched-off modules are only checked for unique IDs, so their classes never load.
 */
final class ModuleManifestValidator {

	public const DUPLICATE_ID            = 'duplicate_id';
	public const RESERVED_ID             = 'reserved_id';
	public const INCOMPATIBLE_CORE       = 'incompatible_core';
	public const INVALID_CONSTRAINT      = 'invalid_constraint';
	public const MISSING_CLASS           = 'missing_class';
	public const INVALID_CLASS           = 'invalid_class';
	public const INVALID_SETTINGS        = 'invalid_settings';
	public const MISSING_DEPENDENCY      = 'missing_dependency';
	public const INACTIVE_DEPENDENCY     = 'inactive_dependency';
	public const INCOMPATIBLE_DEPENDENCY = 'incompatible_dependency';
	public const DUPLICATE_FEATURE       = 'duplicate_feature';
	public const DEPENDENCY_CYCLE        = 'dependency_cycle';
	public const PENDING_MIGRATIONS      = 'pending_migrations';

	/**
	 * Builds a class named in a manifest.
	 *
	 * @var Closure(class-string): object
	 */
	private Closure $build;

	/**
	 * IDs of every registered module, set while resolving.
	 *
	 * @var array<string, true>
	 */
	private array $registered = array();

	/**
	 * Creates the validator.
	 *
	 * @param string                       $coreVersion Version of Mercatus that "core" constraints are checked against.
	 * @param Closure(class-string): object $build       Builds settings providers, usually through the container.
	 */
	public function __construct( private string $coreVersion, Closure $build ) {
		$this->build = $build;
	}

	/**
	 * Decides which modules load.
	 *
	 * @param list<RegisteredModule> $modules Every registered module, Core's first.
	 * @param array<string, bool>    $states  The admin's on/off choices for optional modules.
	 * @param array<string, string>  $blocked Reason by module ID for modules that must not load this time, such as pending migrations.
	 */
	public function resolve( array $modules, array $states, array $blocked = array() ): ModuleResolution {
		$rejected         = array();
		$inactive         = array();
		$wanted           = array();
		$ids              = array_map( static fn( RegisteredModule $module ): string => $module->manifest->id, $modules );
		$counts           = array_count_values( $ids );
		$this->registered = array_fill_keys( $ids, true );

		foreach ( $modules as $module ) {
			$id = $module->manifest->id;
			if ( $counts[ $id ] > 1 ) {
				$rejected[ $id ] = self::DUPLICATE_ID;
			} elseif ( ! $module->fromCore && str_starts_with( $id, 'core.' ) ) {
				$rejected[ $id ] = self::RESERVED_ID;
			} elseif ( $module->optional && ! ( $states[ $id ] ?? $module->onByDefault() ) ) {
				$inactive[] = $id;
			} else {
				$wanted[ $id ] = $module->manifest;
			}
		}

		$settings = array();
		foreach ( $wanted as $id => $manifest ) {
			$problem = $blocked[ $id ] ?? $this->ownProblem( $manifest, $settings );
			if ( null !== $problem ) {
				$rejected[ $id ] = $problem;
			}
		}

		$rejected              = $this->rejectUnmetDependencies( $wanted, $rejected );
		$rejected              = $this->rejectDuplicateFeatures( $wanted, $rejected );
		$rejected              = $this->rejectUnmetDependencies( $wanted, $rejected );
		[ $active, $rejected ] = $this->order( array_diff_key( $wanted, $rejected ), $rejected );

		return new ModuleResolution(
			$active,
			array_intersect_key( $settings, array_flip( array_map( static fn( ModuleManifest $manifest ): string => $manifest->id, $active ) ) ),
			$inactive,
			$rejected
		);
	}

	/**
	 * A problem with the module itself: its core version, its classes or its settings.
	 *
	 * @param ModuleManifest                         $manifest Manifest.
	 * @param array<string, list<SettingDefinition>> $settings Collected settings, filled in for valid modules.
	 */
	private function ownProblem( ModuleManifest $manifest, array &$settings ): ?string {
		foreach ( $manifest->requires as $constraint ) {
			if ( ! VersionConstraint::isValid( $constraint ) ) {
				return self::INVALID_CONSTRAINT;
			}
		}
		if ( isset( $manifest->requires['core'] ) && ! VersionConstraint::satisfies( $this->coreVersion, $manifest->requires['core'] ) ) {
			return self::INCOMPATIBLE_CORE;
		}

		$classes = array_filter( array_merge( array( $manifest->routes, $manifest->migrations, $manifest->permissions, $manifest->dashboardMenu, $manifest->settings, $manifest->hooks ), $manifest->eventSubscribers, $manifest->healthChecks, $manifest->claimSources ) );
		foreach ( $classes as $class ) {
			if ( ! class_exists( $class ) ) {
				return self::MISSING_CLASS;
			}
		}
		if ( null !== $manifest->hooks && ! is_a( $manifest->hooks, ModuleHooks::class, true ) ) {
			return self::INVALID_CLASS;
		}
		if ( null !== $manifest->migrations && ! is_a( $manifest->migrations, MigrationSource::class, true ) ) {
			return self::INVALID_CLASS;
		}
		if ( null !== $manifest->routes && ! is_a( $manifest->routes, RouteProvider::class, true ) ) {
			return self::INVALID_CLASS;
		}
		if ( null !== $manifest->permissions && ! is_a( $manifest->permissions, PermissionProvider::class, true ) ) {
			return self::INVALID_CLASS;
		}
		if ( null !== $manifest->dashboardMenu && ! is_a( $manifest->dashboardMenu, DashboardMenuProvider::class, true ) ) {
			return self::INVALID_CLASS;
		}
		foreach ( $manifest->claimSources as $source ) {
			if ( ! is_a( $source, ClaimSource::class, true ) ) {
				return self::INVALID_CLASS;
			}
		}
		foreach ( $manifest->eventSubscribers as $subscriber ) {
			if ( ! is_a( $subscriber, EventSubscriber::class, true ) ) {
				return self::INVALID_CLASS;
			}
		}
		foreach ( $manifest->healthChecks as $check ) {
			if ( ! is_a( $check, HealthCheck::class, true ) ) {
				return self::INVALID_CLASS;
			}
		}
		if ( null === $manifest->settings ) {
			$settings[ $manifest->id ] = array();
			return null;
		}

		$provider = ( $this->build )( $manifest->settings );
		if ( ! $provider instanceof SettingsProvider ) {
			return self::INVALID_CLASS;
		}
		$definitions = $provider->definitions();
		$keys        = array_map( static fn( SettingDefinition $definition ): string => $definition->key, $definitions );
		foreach ( $definitions as $definition ) {
			if ( $definition->moduleId() !== $manifest->id ) {
				return self::INVALID_SETTINGS;
			}
		}
		if ( count( $keys ) !== count( array_unique( $keys ) ) ) {
			return self::INVALID_SETTINGS;
		}
		$settings[ $manifest->id ] = $definitions;

		return null;
	}

	/**
	 * Rejects modules whose dependencies are missing, switched off, rejected or the wrong version, repeating
	 * until nothing changes so that rejections cascade.
	 *
	 * @param array<string, ModuleManifest> $wanted   Modules that should load.
	 * @param array<string, string>         $rejected Rejections so far.
	 * @return array<string, string>
	 */
	private function rejectUnmetDependencies( array $wanted, array $rejected ): array {
		do {
			$changed = false;
			foreach ( $wanted as $id => $manifest ) {
				if ( isset( $rejected[ $id ] ) ) {
					continue;
				}
				foreach ( $manifest->requires as $dependency => $constraint ) {
					$problem = 'core' === $dependency ? null : $this->dependencyProblem( $wanted, $rejected, $dependency, $constraint );
					if ( null !== $problem ) {
						$rejected[ $id ] = $problem;
						$changed         = true;
						break;
					}
				}
			}
		} while ( $changed );

		return $rejected;
	}

	/**
	 * Why a dependency isn't usable, if it isn't.
	 *
	 * @param array<string, ModuleManifest> $wanted     Modules that should load.
	 * @param array<string, string>         $rejected   Rejections so far.
	 * @param string                        $dependency Module ID.
	 * @param string                        $constraint Version constraint.
	 */
	private function dependencyProblem( array $wanted, array $rejected, string $dependency, string $constraint ): ?string {
		if ( ! isset( $wanted[ $dependency ] ) && ! isset( $rejected[ $dependency ] ) ) {
			return $this->isRegistered( $dependency ) ? self::INACTIVE_DEPENDENCY : self::MISSING_DEPENDENCY;
		}
		if ( isset( $rejected[ $dependency ] ) ) {
			return self::INACTIVE_DEPENDENCY;
		}
		try {
			return VersionConstraint::satisfies( $wanted[ $dependency ]->version, $constraint ) ? null : self::INCOMPATIBLE_DEPENDENCY;
		} catch ( InvalidArgumentException ) {
			return self::INVALID_CONSTRAINT;
		}
	}

	/**
	 * Whether a module ID is registered at all, active or not.
	 *
	 * @param string $id Module ID.
	 */
	private function isRegistered( string $id ): bool {
		return isset( $this->registered[ $id ] );
	}

	/**
	 * Rejects a module that claims a feature an earlier module already provides.
	 *
	 * @param array<string, ModuleManifest> $wanted   Modules that should load.
	 * @param array<string, string>         $rejected Rejections so far.
	 * @return array<string, string>
	 */
	private function rejectDuplicateFeatures( array $wanted, array $rejected ): array {
		$claimed = array();
		foreach ( $wanted as $id => $manifest ) {
			if ( isset( $rejected[ $id ] ) ) {
				continue;
			}
			foreach ( $manifest->provides as $feature ) {
				if ( isset( $claimed[ $feature ] ) ) {
					$rejected[ $id ] = self::DUPLICATE_FEATURE;
					continue 2;
				}
			}
			$claimed += array_fill_keys( $manifest->provides, $id );
		}

		return $rejected;
	}

	/**
	 * Orders modules so dependencies load first; modules left in a cycle are rejected.
	 *
	 * @param array<string, ModuleManifest> $modules  Modules that passed every other check.
	 * @param array<string, string>         $rejected Rejections so far.
	 * @return array{list<ModuleManifest>, array<string, string>}
	 */
	private function order( array $modules, array $rejected ): array {
		$ordered = array();
		$placed  = array();
		do {
			$progress = false;
			foreach ( $modules as $id => $manifest ) {
				if ( isset( $placed[ $id ] ) ) {
					continue;
				}
				$ready = true;
				foreach ( array_keys( $manifest->requires ) as $dependency ) {
					$ready = $ready && ( 'core' === $dependency || isset( $placed[ $dependency ] ) );
				}
				if ( $ready ) {
					$ordered[]     = $manifest;
					$placed[ $id ] = true;
					$progress      = true;
				}
			}
		} while ( $progress );

		foreach ( array_diff_key( $modules, $placed ) as $id => $manifest ) {
			$rejected[ $id ] = self::DEPENDENCY_CYCLE;
		}

		return array( $ordered, $rejected );
	}
}
