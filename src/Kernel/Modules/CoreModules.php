<?php
/**
 * The modules Mercatus itself ships (ROAD-3, KER-14).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Kernel\Modules;

use Mercatus\Core\Contracts\Module\ModuleManifest;
use Mercatus\Core\Identity\Api\IdentityMenu;
use Mercatus\Core\Identity\Api\IdentityPermissions;
use Mercatus\Core\Identity\Api\IdentityRoutes;
use Mercatus\Core\Identity\Api\IdentitySettingsForm;
use Mercatus\Core\Identity\Application\IdentitySettings;
use Mercatus\Core\Identity\Application\PermissionsHealthCheck;
use Mercatus\Core\Identity\Application\RateLimitingHealthCheck;
use Mercatus\Core\Identity\Infrastructure\IdentityHooks;
use Mercatus\Core\Identity\Infrastructure\IdentityMigrations;
use Mercatus\Core\Identity\Infrastructure\WordPressClaimSource;
use Mercatus\Core\Kernel\Http\KernelRoutes;
use Mercatus\Core\Kernel\KernelMenu;
use Mercatus\Core\Kernel\KernelMigrations;
use Mercatus\Core\Kernel\KernelPermissions;
use Mercatus\Core\Kernel\KernelSettings;
use Mercatus\Core\Kernel\KernelSettingsForm;
use Mercatus\Core\Store\Api\StoreMeSection;
use Mercatus\Core\Store\Api\StorePermissions;
use Mercatus\Core\Store\Api\StoreRoutes;
use Mercatus\Core\Store\Api\StoreSettingsForm;
use Mercatus\Core\Store\Application\StoreSettings;
use Mercatus\Core\Store\Infrastructure\StoreEventSubscriber;
use Mercatus\Core\Store\Infrastructure\StoreHooks;
use Mercatus\Core\Store\Infrastructure\StoreMigrations;
use Mercatus\Core\Vendor\Api\VendorMenu;
use Mercatus\Core\Vendor\Api\VendorMeSection;
use Mercatus\Core\Vendor\Api\VendorPermissions;
use Mercatus\Core\Vendor\Api\VendorRoutes;
use Mercatus\Core\Vendor\Api\VendorSettingsForm;
use Mercatus\Core\Vendor\Application\VendorSettings;
use Mercatus\Core\Vendor\Infrastructure\Email\VendorEmailSubscriber;
use Mercatus\Core\Vendor\Infrastructure\VendorClaimSource;
use Mercatus\Core\Vendor\Infrastructure\VendorHooks;
use Mercatus\Core\Vendor\Infrastructure\VendorMigrations;

/**
 * Each module joins this list when it's built. Only Reviews and Shipping will be optional (ARCH-6).
 */
final class CoreModules {

	/**
	 * Core's modules, in load order.
	 *
	 * @param string $version Mercatus version; Core modules share it.
	 * @return list<RegisteredModule>
	 */
	public static function all( string $version ): array {
		return array(
			new RegisteredModule( new ModuleManifest( id: 'core.kernel', version: $version, routes: KernelRoutes::class, migrations: KernelMigrations::class, permissions: KernelPermissions::class, dashboardMenu: KernelMenu::class, settings: KernelSettings::class, settingsForm: KernelSettingsForm::class ), false, true ),
			new RegisteredModule(
				new ModuleManifest(
					id: 'core.identity',
					version: $version,
					routes: IdentityRoutes::class,
					migrations: IdentityMigrations::class,
					permissions: IdentityPermissions::class,
					dashboardMenu: IdentityMenu::class,
					healthChecks: array( PermissionsHealthCheck::class, RateLimitingHealthCheck::class ),
					settings: IdentitySettings::class,
					hooks: IdentityHooks::class,
					claimSources: array( WordPressClaimSource::class ),
					settingsForm: IdentitySettingsForm::class,
				),
				false,
				true
			),
			new RegisteredModule(
				new ModuleManifest(
					id: 'core.vendor',
					version: $version,
					routes: VendorRoutes::class,
					migrations: VendorMigrations::class,
					permissions: VendorPermissions::class,
					dashboardMenu: VendorMenu::class,
					settings: VendorSettings::class,
					hooks: VendorHooks::class,
					claimSources: array( VendorClaimSource::class ),
					meSections: array( VendorMeSection::class ),
					eventSubscribers: array( VendorEmailSubscriber::class ),
					settingsForm: VendorSettingsForm::class,
				),
				false,
				true
			),
			new RegisteredModule(
				new ModuleManifest(
					id: 'core.store',
					version: $version,
					routes: StoreRoutes::class,
					migrations: StoreMigrations::class,
					permissions: StorePermissions::class,
					settings: StoreSettings::class,
					hooks: StoreHooks::class,
					meSections: array( StoreMeSection::class ),
					eventSubscribers: array( StoreEventSubscriber::class ),
					settingsForm: StoreSettingsForm::class,
				),
				false,
				true
			),
		);
	}
}
