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
use Mercatus\Core\Contracts\Events\EventSubscriber;
use Mercatus\Core\Contracts\Identity\CurrentActor;
use Mercatus\Core\Contracts\Identity\IdentityRevisions;
use Mercatus\Core\Contracts\Module\ModuleHooks;
use Mercatus\Core\Contracts\Settings\SettingsReader;
use Mercatus\Core\Database\Seeders\ScaleSeedCommand;
use Mercatus\Core\Identity\Application\FeatureSource;
use Mercatus\Core\Identity\Application\IdentityResolver;
use Mercatus\Core\Identity\Application\ModuleExtensions;
use Mercatus\Core\Identity\Application\RateLimiter;
use Mercatus\Core\Identity\Infrastructure\IdempotencyStore;
use Mercatus\Core\Identity\Infrastructure\IdentityExecutionContext;
use Mercatus\Core\Infrastructure\Audit\AuditStore;
use Mercatus\Core\Infrastructure\Audit\BufferedAuditLog;
use Mercatus\Core\Infrastructure\Database\WpdbTransactionRunner;
use Mercatus\Core\Infrastructure\Outbox\EventDispatcher;
use Mercatus\Core\Infrastructure\Outbox\OutboxEventPublisher;
use Mercatus\Core\Infrastructure\Outbox\OutboxStore;
use Mercatus\Core\Infrastructure\Outbox\OutboxWorker;
use Mercatus\Core\Kernel\Events\DomainEventActionBridge;
use Mercatus\Core\Contracts\Http\RouteProvider;
use Mercatus\Core\Kernel\Http\AccessGate;
use Mercatus\Core\Kernel\Http\PolicyAccessGate;
use Mercatus\Core\Kernel\Http\ResponseFinisher;
use Mercatus\Core\Kernel\Http\WordPressRouteRegistrar;
use Mercatus\Core\Shared\Actor\ExecutionContext;
use Mercatus\Core\Shared\Audit\AuditLog;
use Mercatus\Core\Shared\Events\EventPublisher;
use Mercatus\Core\Shared\Persistence\TransactionRunner;
use DateInterval;
use Mercatus\Core\Infrastructure\Cache\WpCachePort;
use Mercatus\Core\Infrastructure\Cache\WpCoordinationPort;
use Mercatus\Core\Contracts\Database\MigrationSource;
use Mercatus\Core\Contracts\Module\ModuleManifest;
use Mercatus\Core\Infrastructure\Database\SchemaVersionStore;
use Mercatus\Core\Infrastructure\Database\WpdbMigrationContext;
use Mercatus\Core\Kernel\Cli\KernelCommand;
use Mercatus\Core\Kernel\Cli\ManagerCommand;
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
	 * Whether Action Scheduler is executing an action right now (KER-7, TM-39).
	 */
	private bool $inScheduler = false;

	public const POLL_HOOK = 'mercatus_outbox_poll';

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
		self::ensurePoller();
	}

	/**
	 * Schedules the outbox poller once: one recurring Action Scheduler action, never one per event (G-DB-6).
	 */
	public static function ensurePoller(): void {
		$schedule = static function (): void {
			if ( function_exists( 'as_has_scheduled_action' ) && ! as_has_scheduled_action( self::POLL_HOOK ) ) {
				as_schedule_recurring_action( time(), MINUTE_IN_SECONDS, self::POLL_HOOK, array(), 'mercatus', true );
			}
		};
		if ( did_action( 'action_scheduler_init' ) ) {
			$schedule();
		} else {
			add_action( 'action_scheduler_init', $schedule );
		}
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
		$container->factory( \wpdb::class, static fn(): \wpdb => $GLOBALS['wpdb'] );
		$container->factory( SettingsReader::class, static fn( Container $services ): SettingsReader => $services->get( SettingsRegistry::class ) );
		// The actor comes from core.identity (IDN-13); its resolver needs the resolved modules, so it's built on first use.
		$context = new IdentityExecutionContext( $container->get( Clock::class ), static fn(): IdentityResolver => $container->get( IdentityResolver::class ) );
		$container->instance( ExecutionContext::class, $context );
		$container->instance( CurrentActor::class, $context );
		$container->factory( IdentityRevisions::class, static fn( Container $services ): IdentityRevisions => $services->get( IdentityResolver::class ) );
		$container->factory( AccessGate::class, static fn( Container $services ): AccessGate => $services->get( PolicyAccessGate::class ) );
		$container->factory( RateLimiter::class, static fn( Container $services ): RateLimiter => new RateLimiter( $services->get( CoordinationPort::class ), $services->get( SettingsReader::class ), static fn(): int => $services->get( Clock::class )->now()->getTimestamp() ) );
		$container->factory( EventPublisher::class, static fn( Container $services ): EventPublisher => $services->get( OutboxEventPublisher::class ) );
		$container->factory( AuditLog::class, static fn( Container $services ): AuditLog => $services->get( BufferedAuditLog::class ) );
		$container->factory( TransactionRunner::class, static fn( Container $services ): TransactionRunner => $services->get( WpdbTransactionRunner::class ) );
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
		$features = ( new FeatureManifestCompiler() )->compile( $resolution->active );
		$this->container->instance( FeatureManifest::class, $features );
		$this->container->instance( FeatureSource::class, $features );
		$this->container->instance( ModuleExtensions::class, new ModuleExtensions( $resolution->active, fn( string $class_name ): object => $this->container->get( $class_name ) ) );
		$this->container->instance( EventDispatcher::class, new EventDispatcher( fn(): array => $this->subscribers( $resolution->active ) ) );

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

		// Only the site's booted kernel owns routes, the poller and admin and CLI commands; kernels made for
		// activation or tests load modules without them.
		if ( self::$instance === $this ) {
			$this->attachSiteHooks();
		}
	}

	/**
	 * Hooks that belong to the site's one kernel.
	 */
	private function attachSiteHooks(): void {
		add_action( 'rest_api_init', array( $this, 'registerRoutes' ) );
		add_filter( 'rest_request_after_callbacks', array( $this->container->get( ResponseFinisher::class ), 'finish' ), 10, 3 );
		add_action( 'admin_post_mercatus_migrate', array( $this, 'handleMigrateRequest' ) );
		add_action( self::POLL_HOOK, array( $this, 'runPoll' ) );
		add_action( 'action_scheduler_begin_execute', array( $this, 'enterScheduler' ) );
		add_action( 'action_scheduler_after_execute', array( $this, 'leaveScheduler' ) );
		add_action( 'action_scheduler_failed_execute', array( $this, 'leaveScheduler' ) );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			$command = new KernelCommand( $this );
			\WP_CLI::add_command( 'mercatus migrate', array( $command, 'migrate' ) );
			\WP_CLI::add_command( 'mercatus outbox:work', array( $command, 'work' ) );
			$managers = new ManagerCommand( $this );
			\WP_CLI::add_command( 'mercatus manager appoint', array( $managers, 'appoint' ) );
			\WP_CLI::add_command( 'mercatus manager remove', array( $managers, 'remove' ) );
			\WP_CLI::add_command( 'mercatus manager list', array( $managers, 'listManagers' ) );
			// Development tooling, absent from the plugin zip (KER-12).
			if ( class_exists( ScaleSeedCommand::class ) ) {
				\WP_CLI::add_command( 'mercatus seed:scale', new ScaleSeedCommand( $this ) );
			}
		}
	}

	/**
	 * Registers the active modules' routes through the registrar (ARCH-8 point 4).
	 */
	public function registerRoutes(): void {
		$registrar = $this->container->get( WordPressRouteRegistrar::class );
		foreach ( $this->resolution()->active as $manifest ) {
			$provider = null === $manifest->routes ? null : $this->container->get( $manifest->routes );
			if ( $provider instanceof RouteProvider ) {
				$provider->routes( $registrar );
			}
		}
	}

	/**
	 * Switches an optional module and records it (core.kernel §5, KER-9). The switch and its migrations run
	 * first, because DDL would commit an open transaction; the event and the audit entry follow.
	 *
	 * @param string $module_id Module ID.
	 * @param bool   $on        Whether to switch it on.
	 * @throws \Mercatus\Core\Shared\Errors\DomainError When the switch is refused.
	 */
	public function switchModule( string $module_id, bool $on ): void {
		$activation = $this->activation();
		$on ? $activation->activate( $module_id ) : $activation->deactivate( $module_id );

		$this->container->get( TransactionRunner::class )->run(
			function () use ( $module_id, $on ): void {
				$change = $on ? 'activated' : 'deactivated';
				$this->container->get( EventPublisher::class )->publish( 'kernel.module.' . $change, 'module', $module_id, 0, array( 'module' => $module_id ) );
				$this->container->get( AuditLog::class )->record( 'module.' . $change, 'module', $module_id, array( 'active' => $on ) );
			}
		);
	}

	/**
	 * Applies the migrations of every module that would load, in dependency order (KER-5).
	 */
	public function migrate(): MigrationReport {
		return $this->container->get( MigrationRunner::class )->run( $this->candidates );
	}

	/**
	 * Drains the outbox and applies retention, as the system actor. Runs only when Action Scheduler executes it
	 * or from WP-CLI; firing the hook from a request does nothing (TM-39).
	 *
	 * @return \Mercatus\Core\Infrastructure\Outbox\WorkReport|null The work done, or null when refused.
	 */
	public function poll(): ?\Mercatus\Core\Infrastructure\Outbox\WorkReport {
		if ( ! $this->inScheduler && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return null;
		}
		$context = $this->container->get( ExecutionContext::class );
		if ( ! $context instanceof IdentityExecutionContext ) {
			return null;
		}

		return $context->runAsSystem(
			function (): \Mercatus\Core\Infrastructure\Outbox\WorkReport {
				$report = $this->container->get( OutboxWorker::class )->work();
				$this->applyRetention();
				$this->container->get( KernelOption::class )->recordPoll( time() );

				return $report;
			}
		);
	}

	/**
	 * The poller's action callback.
	 */
	public function runPoll(): void {
		$this->poll();
	}

	/**
	 * Notes that Action Scheduler started executing an action.
	 */
	public function enterScheduler(): void {
		$this->inScheduler = true;
	}

	/**
	 * Notes that Action Scheduler finished executing an action.
	 */
	public function leaveScheduler(): void {
		$this->inScheduler = false;
	}

	/**
	 * Purges handled outbox rows past their retention and, when switched on, old audit entries (KER-15).
	 */
	private function applyRetention(): void {
		$settings = $this->settings();
		if ( ! $settings->has( KernelSettings::OUTBOX_RETENTION_DAYS ) ) {
			return;
		}
		$now = $this->container->get( Clock::class )->now();
		$this->container->get( OutboxStore::class )->purge( $now->sub( new DateInterval( 'P' . $settings->int( KernelSettings::OUTBOX_RETENTION_DAYS ) . 'D' ) ) );
		if ( in_array( 'core.identity', $this->resolution()->activeIds(), true ) ) {
			$this->container->get( IdempotencyStore::class )->purge( $now );
		}
		$years = $settings->int( KernelSettings::AUDIT_PURGE_AFTER_YEARS );
		if ( $years > 0 ) {
			$this->container->get( AuditStore::class )->purge( $now->sub( new DateInterval( 'P' . $years . 'Y' ) ) );
		}
	}

	/**
	 * The WordPress bridge plus the active modules' subscribers.
	 *
	 * @param list<ModuleManifest> $active Active modules.
	 * @return list<EventSubscriber>
	 */
	private function subscribers( array $active ): array {
		$subscribers = array( new DomainEventActionBridge() );
		foreach ( $active as $manifest ) {
			foreach ( $manifest->eventSubscribers as $class_name ) {
				$subscriber = $this->container->get( $class_name );
				if ( $subscriber instanceof EventSubscriber ) {
					$subscribers[] = $subscriber;
				}
			}
		}

		return $subscribers;
	}

	/**
	 * Runs migrations from the admin notice's link, for administrators only.
	 */
	public function handleMigrateRequest(): void {
		check_admin_referer( 'mercatus_migrate' );
		if ( ! self::administrator() ) {
			wp_die( esc_html__( 'You are not allowed to update the Mercatus database.', 'mercatus' ), '', array( 'response' => 403 ) );
		}
		$this->migrate();
		self::ensurePoller();
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
	 * Whether the current user is a marketplace administrator (IDN-15). Notices shown before modules load
	 * keep WordPress's own checks.
	 */
	private static function administrator(): bool {
		return null !== self::$instance && self::$instance->container->get( CurrentActor::class )->identity()->isAdministrator();
	}

	/**
	 * Tells administrators the database needs updating, with a link that does it.
	 */
	private static function migrationNotice(): void {
		add_action(
			'admin_notices',
			static function (): void {
				if ( ! self::administrator() ) {
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
