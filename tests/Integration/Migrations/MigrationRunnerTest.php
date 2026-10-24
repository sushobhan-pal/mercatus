<?php
/**
 * Forward-only migrations with checksums and a lock (TEST-3 row 1, DATA-1, KER-5).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Integration\Migrations;

use Mercatus\Core\Contracts\Module\ModuleManifest;
use Mercatus\Core\Contracts\Module\ModuleRegistrar;
use Mercatus\Core\Infrastructure\Database\SchemaVersionStore;
use Mercatus\Core\Infrastructure\Database\WpdbMigrationContext;
use Mercatus\Core\Kernel\Kernel;
use Mercatus\Core\Kernel\KernelOption;
use Mercatus\Core\Kernel\Migrations\MigrationRunner;
use Mercatus\Core\Kernel\Modules\ModuleManifestValidator;
use Mercatus\Core\Tests\Fixtures\Clock\FrozenClock;
use Mercatus\Core\Tests\Fixtures\Modules\BrokenMigrations;
use Mercatus\Core\Tests\Fixtures\Modules\ProbeMigrations;
use WP_UnitTestCase;

/**
 * Up, idempotent re-run, checksum drift, a failing migration, the lock, and Pro's lazy migration on
 * activation (TEST-1 Layer 4).
 */
final class MigrationRunnerTest extends WP_UnitTestCase {

	private const TABLES = array( 'mercatus_pro_probe_items', 'mercatus_pro_probe_notes', 'mercatus_pro_broken_ok', 'mercatus_pro_broken_late' );

	private FrozenClock $clock;

	public function set_up(): void {
		parent::set_up();
		$this->clock = new FrozenClock();
	}

	public function tear_down(): void {
		global $wpdb;
		remove_action( 'mercatus_register_modules', array( $this, 'registerProbe' ) );
		foreach ( self::TABLES as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" ); // phpcs:ignore WordPress.DB -- test cleanup of fixture tables.
		}
		$wpdb->query( "DROP TEMPORARY TABLE IF EXISTS {$wpdb->prefix}mercatus_schema_versions" ); // phpcs:ignore WordPress.DB -- removes the test's temporary ledger.
		parent::tear_down();
	}

	public function testAppliesPendingMigrationsInOrderAndRecordsThem(): void {
		$report = $this->runner()->run( array( $this->probe() ) );

		$this->assertTrue( $report->succeeded() );
		$this->assertSame( array( 'pro.probe' => array( 1, 2 ) ), $report->applied );
		$this->assertTrue( $this->tableExists( 'mercatus_pro_probe_items' ) );
		$this->assertTrue( $this->tableExists( 'mercatus_pro_probe_notes' ) );
		$this->assertSame( array( 1, 2 ), array_keys( $this->store()->applied( 'pro.probe' ) ) );
		$this->assertSame( 2, ( new KernelOption() )->migrations()['pro.probe'] ?? null );
	}

	public function testRunningAgainChangesNothing(): void {
		$this->runner()->run( array( $this->probe() ) );

		$report = $this->runner()->run( array( $this->probe() ) );

		$this->assertTrue( $report->succeeded() );
		$this->assertSame( array(), $report->applied );
	}

	public function testStopsAModuleWhoseAppliedMigrationChanged(): void {
		global $wpdb;
		$this->runner()->run( array( $this->probe() ) );
		$wpdb->update(
			$wpdb->prefix . 'mercatus_schema_versions',
			array( 'checksum' => str_repeat( 'f', 64 ) ),
			array(
				'module_id' => 'pro.probe',
				'version'   => 1,
			)
		); // phpcs:ignore WordPress.DB -- simulates an edited migration file.

		$report = $this->runner()->run( array( $this->probe() ) );

		$this->assertSame( array( 'pro.probe' => 1 ), $report->drifted );
		$this->assertFalse( $report->succeeded() );
	}

	public function testStopsAModuleAtAFailingMigration(): void {
		$report = $this->runner()->run( array( new ModuleManifest( 'pro.broken', '1.0.0', migrations: BrokenMigrations::class ) ) );

		$this->assertSame( array( 'pro.broken' => array( 1 ) ), $report->applied );
		$this->assertSame( array( 'pro.broken' => 2 ), $report->failed );
		$this->assertTrue( $this->tableExists( 'mercatus_pro_broken_ok' ) );
		$this->assertFalse( $this->tableExists( 'mercatus_pro_broken_late' ) );
		$this->assertSame( 1, ( new KernelOption() )->migrations()['pro.broken'] ?? null );
	}

	public function testMigratesOneProcessAtATime(): void {
		$store = $this->store();
		$store->install();
		$this->assertTrue( $store->acquireLock() );

		$report = $this->runner()->run( array( $this->probe() ) );

		$this->assertTrue( $report->locked );
		$this->assertFalse( $this->tableExists( 'mercatus_pro_probe_items' ) );
	}

	public function testTakesOverAnAbandonedLock(): void {
		$this->store()->install();
		$this->assertTrue( ( new SchemaVersionStore( $GLOBALS['wpdb'], new FrozenClock( $this->clock->now()->modify( '-11 minutes' ) ) ) )->acquireLock() );

		$report = $this->runner()->run( array( $this->probe() ) );

		$this->assertFalse( $report->locked );
		$this->assertTrue( $report->succeeded() );
	}

	public function testBlocksAModuleWithPendingMigrationsAndMigratesItWhenSwitchedOn(): void {
		add_action( 'mercatus_register_modules', array( $this, 'registerProbe' ) );
		$kernel = Kernel::create( MERCATUS_VERSION );
		$kernel->start();

		$kernel->activation()->activate( 'pro.probe' );

		$this->assertTrue( $this->tableExists( 'mercatus_pro_probe_notes' ) );
		$next = Kernel::create( MERCATUS_VERSION );
		$next->start();
		$this->assertContains( 'pro.probe', $next->resolution()->activeIds() );

		( new KernelOption() )->recordMigration( 'pro.probe', 1 );
		$behind = Kernel::create( MERCATUS_VERSION );
		$behind->start();
		$this->assertSame( ModuleManifestValidator::PENDING_MIGRATIONS, $behind->resolution()->rejected['pro.probe'] ?? null );
	}

	/**
	 * Registers the probe module, as an add-on would.
	 *
	 * @param ModuleRegistrar $registrar Registrar.
	 */
	public function registerProbe( ModuleRegistrar $registrar ): void {
		$registrar->register( $this->probe() );
	}

	/**
	 * The probe module with two migrations.
	 */
	private function probe(): ModuleManifest {
		return new ModuleManifest( 'pro.probe', '1.0.0', array( 'core' => '^0.1' ), migrations: ProbeMigrations::class );
	}

	/**
	 * A runner over the test database.
	 */
	private function runner(): MigrationRunner {
		global $wpdb;

		return new MigrationRunner( $this->store(), new WpdbMigrationContext( $wpdb ), new KernelOption(), static fn( string $class_name ): object => new $class_name() );
	}

	/**
	 * The migration ledger.
	 */
	private function store(): SchemaVersionStore {
		global $wpdb;

		return new SchemaVersionStore( $wpdb, $this->clock );
	}

	/**
	 * Whether a table exists, including the test suite's temporary tables, which SHOW TABLES leaves out.
	 *
	 * @param string $table Table name without the site prefix.
	 */
	private function tableExists( string $table ): bool {
		global $wpdb;
		$wpdb->suppress_errors( true );
		$exists = false !== $wpdb->query( "SELECT 1 FROM {$wpdb->prefix}{$table} LIMIT 0" ); // phpcs:ignore WordPress.DB -- existence check on a fixture table.
		$wpdb->suppress_errors( false );

		return $exists;
	}
}
