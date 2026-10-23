<?php
/**
 * TransactionRunner over $wpdb (ARCH-10, API-3, KER-7).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Infrastructure\Database;

use Mercatus\Core\Infrastructure\Audit\AuditStore;
use Mercatus\Core\Infrastructure\Outbox\OutboxProcessor;
use Mercatus\Core\Infrastructure\Outbox\OutboxStore;
use Mercatus\Core\Infrastructure\Outbox\OutboxWorker;
use Mercatus\Core\Shared\Clock\Clock;
use Mercatus\Core\Shared\Events\DomainEvent;
use Mercatus\Core\Shared\Events\EventLane;
use Mercatus\Core\Shared\Id\Uuid;
use Mercatus\Core\Shared\Persistence\TransactionRunner;
use Throwable;

/**
 * The outermost run starts a transaction; nested runs use savepoints. Before the outermost commit it writes
 * the buffered events and audit entries, so state, audit and outbox commit together. After commit it claims
 * its own critical events and hands them to subscribers; a failure there leaves the event for the poller.
 *
 * With $useSavepoints, even the outermost run uses a savepoint: for code that already runs inside a
 * transaction it doesn't own, such as the WordPress test suite's.
 */
final class WpdbTransactionRunner implements TransactionRunner {

	private int $depth = 0;

	/**
	 * Creates the runner.
	 *
	 * @param \wpdb          $db            WordPress database.
	 * @param UnitOfWork      $unit          Transaction buffer.
	 * @param OutboxStore     $outbox        Outbox.
	 * @param AuditStore      $audit         Audit log.
	 * @param OutboxProcessor $processor     Critical-lane handling after commit.
	 * @param Clock           $clock         Current time.
	 * @param bool            $useSavepoints Use a savepoint for the outermost run too.
	 */
	public function __construct(
		private \wpdb $db,
		private UnitOfWork $unit,
		private OutboxStore $outbox,
		private AuditStore $audit,
		private OutboxProcessor $processor,
		private Clock $clock,
		private bool $useSavepoints = false,
	) {}

	public function run( callable $work ): mixed {
		$this->begin();
		try {
			$result = $work();
			if ( 1 === $this->depth ) {
				[ $events, $audit ] = $this->unit->drain();
				$this->outbox->insertMany( $events, $this->clock->now() );
				$this->audit->insertMany( $audit );
			}
		} catch ( Throwable $error ) {
			$this->rollback();
			throw $error;
		}
		$this->commit();

		if ( 0 === $this->depth ) {
			$this->handleCritical( $events ?? array() );
		}

		return $result;
	}

	/**
	 * Hands this transaction's critical events to subscribers, claiming them first so the poller can't too.
	 *
	 * @param list<DomainEvent> $events Committed events.
	 */
	private function handleCritical( array $events ): void {
		$uuids = array();
		foreach ( $events as $event ) {
			if ( EventLane::Critical === $event->lane ) {
				$uuids[] = $event->eventId;
			}
		}
		if ( array() === $uuids ) {
			return;
		}
		$token = Uuid::v7( $this->clock );
		$this->processor->process( $this->outbox->claimEvents( $uuids, $token, $this->clock->now(), OutboxWorker::LEASE_SECONDS ), $token );
	}

	/**
	 * Opens a level.
	 */
	private function begin(): void {
		$statement = 0 === $this->depth && ! $this->useSavepoints ? 'START TRANSACTION' : 'SAVEPOINT ' . $this->savepoint();
		$this->execute( $statement );
		++$this->depth;
		$this->unit->begin();
	}

	/**
	 * Closes a level, keeping its work.
	 */
	private function commit(): void {
		--$this->depth;
		$this->unit->commit();
		$this->execute( 0 === $this->depth && ! $this->useSavepoints ? 'COMMIT' : 'RELEASE SAVEPOINT ' . $this->savepoint() );
	}

	/**
	 * Closes a level, undoing its work.
	 */
	private function rollback(): void {
		--$this->depth;
		$this->unit->rollback();
		$this->execute( 0 === $this->depth && ! $this->useSavepoints ? 'ROLLBACK' : 'ROLLBACK TO SAVEPOINT ' . $this->savepoint() );
	}

	/**
	 * Savepoint name for the current depth.
	 */
	private function savepoint(): string {
		return 'mercatus_' . $this->depth;
	}

	/**
	 * Runs a transaction-control statement.
	 *
	 * @param string $statement Statement.
	 */
	private function execute( string $statement ): void {
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- fixed transaction-control statements.
		$this->db->query( $statement );
	}
}
