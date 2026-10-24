<?php
/**
 * Switching a module is recorded (core.kernel §5, KER-9).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Integration\Kernel;

use Mercatus\Core\Contracts\Module\ModuleManifest;
use Mercatus\Core\Contracts\Module\ModuleRegistrar;
use Mercatus\Core\Infrastructure\Audit\AuditStore;
use Mercatus\Core\Infrastructure\Database\UnitOfWork;
use Mercatus\Core\Infrastructure\Database\WpdbTransactionRunner;
use Mercatus\Core\Infrastructure\Outbox\OutboxProcessor;
use Mercatus\Core\Infrastructure\Outbox\OutboxStore;
use Mercatus\Core\Kernel\Kernel;
use Mercatus\Core\Shared\Clock\Clock;
use Mercatus\Core\Shared\Errors\DomainError;
use Mercatus\Core\Shared\Events\DomainEvent;
use Mercatus\Core\Shared\Persistence\TransactionRunner;
use WP_UnitTestCase;

/**
 * Each switch writes an audit entry and publishes kernel.module.activated or .deactivated; a refused switch
 * records nothing.
 */
final class ModuleSwitchAuditTest extends WP_UnitTestCase {

	/**
	 * Names of events that reached WordPress.
	 *
	 * @var list<string>
	 */
	private array $events = array();

	public function set_up(): void {
		parent::set_up();
		add_action( 'mercatus_register_modules', array( $this, 'registerProbe' ) );
		add_action( 'mercatus_event', array( $this, 'onEvent' ) );
	}

	public function tear_down(): void {
		remove_action( 'mercatus_register_modules', array( $this, 'registerProbe' ) );
		remove_action( 'mercatus_event', array( $this, 'onEvent' ) );
		parent::tear_down();
	}

	public function testRecordsEachSwitch(): void {
		$kernel = $this->kernel();

		$kernel->switchModule( 'pro.switchable', true );
		$kernel->switchModule( 'pro.switchable', false );

		$this->assertSame( array( 'kernel.module.activated', 'kernel.module.deactivated' ), $this->events );
		$this->assertSame( array( 'module.activated', 'module.deactivated' ), $this->auditActions() );
	}

	public function testARefusedSwitchRecordsNothing(): void {
		$this->expectException( DomainError::class );
		try {
			$this->kernel()->switchModule( 'core.kernel', false );
		} finally {
			$this->assertSame( array(), $this->events );
			$this->assertSame( array(), $this->auditActions() );
		}
	}

	/**
	 * Registers an optional module with nothing to load.
	 *
	 * @param ModuleRegistrar $registrar Registrar.
	 */
	public function registerProbe( ModuleRegistrar $registrar ): void {
		$registrar->register( new ModuleManifest( 'pro.switchable', '1.0.0' ) );
	}

	/**
	 * Records an event name.
	 *
	 * @param DomainEvent $event Event.
	 */
	public function onEvent( DomainEvent $event ): void {
		$this->events[] = $event->name;
	}

	/**
	 * A started kernel whose transactions use savepoints inside the test's transaction.
	 */
	private function kernel(): Kernel {
		global $wpdb;
		$kernel   = Kernel::create( MERCATUS_VERSION );
		$services = $kernel->container();
		$kernel->start();
		$services->instance( TransactionRunner::class, new WpdbTransactionRunner( $wpdb, $services->get( UnitOfWork::class ), $services->get( OutboxStore::class ), $services->get( AuditStore::class ), $services->get( OutboxProcessor::class ), $services->get( Clock::class ), true ) );

		return $kernel;
	}

	/**
	 * Audit actions recorded for the probe module.
	 *
	 * @return list<string>
	 */
	private function auditActions(): array {
		global $wpdb;
		$actions = $wpdb->get_col( $wpdb->prepare( 'SELECT action FROM %i WHERE resource_id = %s ORDER BY id', $wpdb->prefix . 'mercatus_audit_log', 'pro.switchable' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test assertion.

		return array_values( array_map( 'strval', $actions ) );
	}
}
