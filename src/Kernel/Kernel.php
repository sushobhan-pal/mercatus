<?php
/**
 * Boots Mercatus (KER-1, KER-2).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Kernel;

use Mercatus\Core\Contracts\Module\ModuleHooks;
use Mercatus\Core\Kernel\Features\FeatureManifest;
use Mercatus\Core\Kernel\Features\FeatureManifestCompiler;
use Mercatus\Core\Kernel\Modules\CoreModules;
use Mercatus\Core\Kernel\Modules\ModuleActivation;
use Mercatus\Core\Kernel\Modules\ModuleManifestValidator;
use Mercatus\Core\Kernel\Modules\ModuleRegistry;
use Mercatus\Core\Kernel\Modules\ModuleResolution;
use Mercatus\Core\Kernel\Modules\ModuleStateStore;
use Mercatus\Core\Kernel\Settings\OptionSettingsStore;
use Mercatus\Core\Kernel\Settings\SettingsRegistry;
use Mercatus\Core\Kernel\Settings\SettingsStore;

/**
 * On `plugins_loaded`: checks the stack, collects Core's and add-ons' modules, decides which load, registers
 * their settings and hooks, and compiles the feature manifest. Switched-off and rejected modules are never
 * built. Boot runs no queries of its own on an installed site.
 */
final class Kernel {

	private static ?self $instance = null;

	private ?ModuleRegistry $registry = null;

	private ?ModuleResolution $resolution = null;

	/**
	 * Creates a kernel.
	 *
	 * @param Container $container Service container.
	 * @param string    $version   Mercatus version.
	 */
	public function __construct( private Container $container, private string $version ) {}

	/**
	 * Boots the site's kernel once; hooked to `plugins_loaded` by mercatus.php.
	 */
	public static function boot(): void {
		if ( null !== self::$instance ) {
			return;
		}
		$unmet = Requirements::unmet();
		if ( array() !== $unmet ) {
			self::notice( $unmet );
			return;
		}
		self::$instance = self::create( MERCATUS_VERSION );
		self::$instance->start();
	}

	/**
	 * The booted kernel, or null when Mercatus didn't boot.
	 */
	public static function instance(): ?self {
		return self::$instance;
	}

	/**
	 * A kernel wired to WordPress storage, not yet started.
	 *
	 * @param string $version Mercatus version.
	 */
	public static function create( string $version ): self {
		$container = new Container();
		$container->instance( ModuleStateStore::class, new KernelOption() );
		$container->instance( SettingsStore::class, new OptionSettingsStore() );
		$kernel = new self( $container, $version );
		$container->instance( self::class, $kernel );

		return $kernel;
	}

	/**
	 * Loads the modules.
	 */
	public function start(): void {
		$registry = new ModuleRegistry( CoreModules::all( $this->version ) );
		$this->container->instance( ModuleRegistry::class, $registry );
		/**
		 * Lets add-ons register their modules (ARCH-8 point 8, KER-13).
		 *
		 * @param \Mercatus\Core\Contracts\Module\ModuleRegistrar $registry Call register() once per module.
		 */
		do_action( 'mercatus_register_modules', $registry );

		$validator  = new ModuleManifestValidator( $this->version, fn( string $class_name ): object => $this->container->get( $class_name ) );
		$resolution = $validator->resolve( $registry->modules(), $this->container->get( ModuleStateStore::class )->states() );

		$settings = $this->container->get( SettingsRegistry::class );
		foreach ( $resolution->settings as $module_id => $definitions ) {
			$settings->register( $module_id, $definitions );
		}
		$this->container->instance( FeatureManifest::class, ( new FeatureManifestCompiler() )->compile( $resolution->active ) );

		foreach ( $resolution->active as $manifest ) {
			$hooks = null === $manifest->hooks ? null : $this->container->get( $manifest->hooks );
			if ( $hooks instanceof ModuleHooks ) {
				$hooks->register();
			}
		}

		if ( array() !== $resolution->rejected ) {
			$names = array();
			foreach ( $resolution->rejected as $module_id => $reason ) {
				$names[] = $module_id . ' (' . $reason . ')';
			}
			/* translators: %s: module IDs with the reason each was rejected. */
			self::notice( array( sprintf( __( 'Mercatus could not load these modules: %s.', 'mercatus' ), implode( ', ', $names ) ) ) );
		}

		$this->registry   = $registry;
		$this->resolution = $resolution;
	}

	/**
	 * Which modules loaded and why others didn't.
	 */
	public function resolution(): ModuleResolution {
		return $this->resolution ?? new ModuleResolution( array(), array(), array(), array() );
	}

	/**
	 * The active modules' features.
	 */
	public function features(): FeatureManifest {
		return $this->container->get( FeatureManifest::class );
	}

	/**
	 * The active modules' settings.
	 */
	public function settings(): SettingsRegistry {
		return $this->container->get( SettingsRegistry::class );
	}

	/**
	 * Switches optional modules on and off; changes apply from the next boot.
	 */
	public function activation(): ModuleActivation {
		return new ModuleActivation( $this->registry ?? new ModuleRegistry( CoreModules::all( $this->version ) ), $this->container->get( ModuleStateStore::class ) );
	}

	/**
	 * The service container.
	 */
	public function container(): Container {
		return $this->container;
	}

	/**
	 * Shows administrators an error notice in wp-admin.
	 *
	 * @param list<string> $messages Messages.
	 */
	private static function notice( array $messages ): void {
		add_action(
			'admin_notices',
			static function () use ( $messages ): void {
				if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'activate_plugins' ) ) {
					return;
				}
				foreach ( $messages as $message ) {
					printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $message ) );
				}
			}
		);
	}
}
