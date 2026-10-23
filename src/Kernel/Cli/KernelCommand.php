<?php
/**
 * WP-CLI commands for the kernel (KER-5).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Kernel\Cli;

use Mercatus\Core\Kernel\Kernel;

/**
 * `wp mercatus migrate` applies pending migrations; `wp mercatus outbox:work` drains the outbox once.
 */
final class KernelCommand {

	/**
	 * Creates the command.
	 *
	 * @param Kernel $kernel Booted kernel.
	 */
	public function __construct( private Kernel $kernel ) {}

	/**
	 * Applies pending migrations of every module that should load.
	 */
	public function migrate(): void {
		$report = $this->kernel->migrate();
		foreach ( $report->applied as $module_id => $versions ) {
			\WP_CLI::log( $module_id . ': applied ' . implode( ', ', $versions ) );
		}
		if ( $report->locked ) {
			\WP_CLI::error( 'Another process is migrating; try again shortly.' );
		}
		foreach ( $report->drifted as $module_id => $version ) {
			\WP_CLI::error( $module_id . ': migration ' . $version . ' changed after it was applied.', false );
		}
		foreach ( $report->failed as $module_id => $version ) {
			\WP_CLI::error( $module_id . ': migration ' . $version . ' failed.', false );
		}
		if ( $report->succeeded() ) {
			Kernel::ensurePoller();
			\WP_CLI::success( 'The Mercatus database is up to date.' );
		}
	}

	/**
	 * Handles waiting outbox events once, for sites that run a real cron instead of Action Scheduler's.
	 */
	public function work(): void {
		$report = $this->kernel->poll();
		if ( null === $report ) {
			\WP_CLI::error( 'The outbox worker did not run.' );
		}
		\WP_CLI::success( sprintf( 'Handled %d, superseded %d, retried %d, failed %d.', $report->processed, $report->superseded, $report->retried, $report->failed ) );
	}
}
