<?php
/**
 * Applies modules' migrations (DATA-1, KER-5).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Kernel\Migrations;

use Closure;
use Mercatus\Core\Contracts\Database\Migration;
use Mercatus\Core\Contracts\Database\MigrationContext;
use Mercatus\Core\Contracts\Database\MigrationSource;
use Mercatus\Core\Contracts\Module\ModuleManifest;
use Mercatus\Core\Infrastructure\Database\SchemaVersionStore;
use Mercatus\Core\Kernel\KernelOption;
use Throwable;

/**
 * Forward only and one process at a time. Before applying anything for a module, it checks that every
 * migration already applied still has the same checksum; a changed or missing file is drift, and that module
 * gets no further migrations until it's resolved. Statements aren't wrapped in a transaction, because DDL
 * commits implicitly in MySQL and MariaDB.
 */
final class MigrationRunner {

	/**
	 * Builds a manifest's MigrationSource, usually through the container.
	 *
	 * @var Closure(class-string): object
	 */
	private Closure $build;

	/**
	 * Creates the runner.
	 *
	 * @param SchemaVersionStore            $versions Ledger and lock.
	 * @param MigrationContext              $context  Where migrations run.
	 * @param KernelOption                  $option   Keeps applied versions for boot (KER-5).
	 * @param Closure(class-string): object $build    Builds migration sources.
	 */
	public function __construct( private SchemaVersionStore $versions, private MigrationContext $context, private KernelOption $option, Closure $build ) {
		$this->build = $build;
	}

	/**
	 * Migrates modules in the order given, which must be dependency order.
	 *
	 * @param list<ModuleManifest> $modules Modules to migrate.
	 */
	public function run( array $modules ): MigrationReport {
		$report = new MigrationReport();
		$this->versions->install();
		if ( ! $this->versions->acquireLock() ) {
			$report->locked = true;
			return $report;
		}

		try {
			foreach ( $modules as $manifest ) {
				if ( null !== $manifest->migrations ) {
					$this->migrate( $manifest, $report );
				}
			}
		} finally {
			$this->versions->releaseLock();
		}

		return $report;
	}

	/**
	 * Migrates one module.
	 *
	 * @param ModuleManifest  $manifest Module.
	 * @param MigrationReport $report   Report to fill in.
	 */
	private function migrate( ModuleManifest $manifest, MigrationReport $report ): void {
		$source = null === $manifest->migrations ? null : ( $this->build )( $manifest->migrations );
		if ( ! $source instanceof MigrationSource ) {
			$report->failed[ $manifest->id ] = 0;
			return;
		}
		$files   = MigrationFiles::in( $source->directory() );
		$applied = $this->versions->applied( $manifest->id );
		foreach ( $applied as $version => $checksum ) {
			if ( ! isset( $files[ $version ] ) || MigrationFiles::checksum( $files[ $version ] ) !== $checksum ) {
				$report->drifted[ $manifest->id ] = $version;
				return;
			}
		}

		$latest = array() === $applied ? 0 : (int) max( array_keys( $applied ) );
		foreach ( $files as $version => $path ) {
			if ( isset( $applied[ $version ] ) ) {
				continue;
			}
			try {
				$migration = require $path;
				if ( ! $migration instanceof Migration ) {
					throw new \UnexpectedValueException( 'A migration file must return a Migration.' );
				}
				$migration->up( $this->context );
			} catch ( Throwable ) {
				$report->failed[ $manifest->id ] = $version;
				break;
			}
			$this->versions->record( $manifest->id, $version, MigrationFiles::checksum( $path ) );
			$report->applied[ $manifest->id ][] = $version;
			$latest                             = max( $latest, $version );
		}
		$this->option->recordMigration( $manifest->id, $latest );
	}
}
