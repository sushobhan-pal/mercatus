<?php
/**
 * Boots Mercatus (KER-1, KER-2).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Kernel;

use Mercatus\Core\Contracts\Cache\CachePort;
use Mercatus\Core\Contracts\Cache\CoordinationPort;
use Mercatus\Core\Contracts\Module\ModuleHooks;
use Mercatus\Core\Infrastructure\Cache\WpCachePort;
use Mercatus\Core\Infrastructure\Cache\WpCoordinationPort;
use Mercatus\Core\Contracts\Database\MigrationSource;
use Mercatus\Core\Contracts\Module\ModuleManifest;
use Mercatus\Core\Infrastructure\Database\SchemaVersionStore;
use Mercatus\Core\Infrastructure\Database\WpdbMigrationContext;
use Mercatus\Core\Kernel\Cli\KernelCommand;
use Mercatus\Core\Kernel\Migrations\MigrationFiles;
use Mercatus\Core\Kernel\Migrations\MigrationReport;
use Mercatus\Core\Kernel\Migrations\MigrationRunner;
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
use Mercatus\Core\Shared\Clock\Clock;
use Mercatus\Core\Shared\Clock\SystemClock;

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
	 * Modules that would load if their migrations were applied, in dependency order.
	 *
	 * @var list<ModuleManifest>
	 */
	private array $candidates = array();

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
	 * Applies migrations when the plugin is activated (KER-5); hooked by mercatus.php.
	 */
	public static function activate(): void {
		if ( array() !== Requirements::unmet() ) {
			return;
		}
		$kernel = self::create( MERCATUS_VERSION );
		$kernel->start();
		$kernel->migrate();
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
		$container->instance( SettingsStore::class, new OptionSettingsStore() );
		$container->instance( Clock::class, new SystemClock() );
		// Built on first use, so boot itself never touches the cache (PLAT-3, KER-6).
		$container->factory( CoordinationPort::class, static fn( Container $services ): CoordinationPort => new WpCoordinationPort( $services->get( Clock::class ) ) );
		$container->factory( CachePort::class, static fn( Container $services ): CachePort => new WpCachePort( $services->get( Clock::class ), $services->get( CoordinationPort::class ) ) );
		$option = new KernelOption();
		$container->instance( KernelOption::class, $option );
		$container->instance( ModuleStateStore::class, $option );
		$container->factory(
			MigrationRunner::class,
			static function ( Container $services ) use ( $option ): MigrationRunner {
				global $wpdb;
				return new MigrationRunner( new SchemaVersionStore( $wpdb, $services->get( Clock::class ) ), new WpdbMigrationContext( $wpdb ), $option, static fn( string $class_name ): object => $services->get( $class_name ) );
			}
		);
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

		$validator        = new ModuleManifestValidator( $this->version, fn( string $class_name ): object => $this->container->get( $class_name ) );
		$states           = $this->container->get( ModuleStateStore::class )->states();
		$resolution       = $validator->resolve( $registry->modules(), $states );
		$this->candidates = $resolution->active;
		$pending          = $this->pendingMigrations( $resolution->active );
		if ( array() !== $pending ) {
			// Fail closed: a module never runs against tables it doesn't have yet (ARCH-6, KER-5).
			$resolution = $validator->resolve( $registry->modules(), $states, array_fill_keys( $pending, ModuleManifestValidator::PENDING_MIGRATIONS ) );
			self::migrationNotice();
		}

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

		add_action( 'admin_post_mercatus_migrate', array( $this, 'handleMigrateRequest' ) );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'mercatus migrate', array( new KernelCommand( $this ), 'migrate' ) );
		}

		$this->registry   = $registry;
		$this->resolution = $resolution;
	}

	/**
	 * Applies the migrations of every module that would load, in dependency order (KER-5).
	 */
	public function migrate(): MigrationReport {
		return $this->container->get( MigrationRunner::class )->run( $this->candidates );
	}

	/**
	 * Runs migrations from the admin notice's link, for administrators only.
	 */
	public function handleMigrateRequest(): void {
		check_admin_referer( 'mercatus_migrate' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to update the Mercatus database.', 'mercatus' ), '', array( 'response' => 403 ) );
		}
		$this->migrate();
		wp_safe_redirect( admin_url() );
		exit;
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
		$registry = $this->registry ?? new ModuleRegistry( CoreModules::all( $this->version ) );

		return new ModuleActivation(
			$registry,
			$this->container->get( ModuleStateStore::class ),
			function ( string $module_id ) use ( $registry ): void {
				// A switched-on module gets its tables right away (DATA-3); its dependencies are already on.
				$module = $registry->find( $module_id );
				if ( null !== $module ) {
					$this->container->get( MigrationRunner::class )->run( array( $module->manifest ) );
				}
			}
		);
	}

	/**
	 * The service container.
	 */
	public function container(): Container {
		return $this->container;
	}

	/**
	 * Modules whose migration folder has a version newer than the one applied. Reads files and the autoloaded
	 * kernel option only, so it costs no query.
	 *
	 * @param list<ModuleManifest> $modules Active modules.
	 * @return list<string> Module IDs.
	 */
	private function pendingMigrations( array $modules ): array {
		$applied = $this->container->get( KernelOption::class )->migrations();
		$pending = array();
		foreach ( $modules as $manifest ) {
			$source = null === $manifest->migrations ? null : $this->container->get( $manifest->migrations );
			if ( $source instanceof MigrationSource && MigrationFiles::latest( $source->directory() ) > ( $applied[ $manifest->id ] ?? 0 ) ) {
				$pending[] = $manifest->id;
			}
		}

		return $pending;
	}

	/**
	 * Tells administrators the database needs updating, with a link that does it.
	 */
	private static function migrationNotice(): void {
		add_action(
			'admin_notices',
			static function (): void {
				if ( ! current_user_can( 'manage_woocommerce' ) ) {
					return;
				}
				printf(
					'<div class="notice notice-warning"><p>%s <a class="button" href="%s">%s</a></p></div>',
					esc_html__( 'Mercatus needs to update its database before some modules can run.', 'mercatus' ),
					esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=mercatus_migrate' ), 'mercatus_migrate' ) ),
					esc_html__( 'Update database', 'mercatus' )
				);
			}
		);
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
