<?php
/**
 * What the current transaction has recorded (API-3).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Infrastructure\Database;

use LogicException;
use Mercatus\Core\Infrastructure\Audit\AuditRecord;
use Mercatus\Core\Shared\Events\DomainEvent;

/**
 * Buffers events and audit records until the transaction runner writes them. Each nesting level keeps a mark,
 * so rolling back an inner level drops only what it recorded.
 */
final class UnitOfWork {

	/**
	 * Buffered events.
	 *
	 * @var list<DomainEvent>
	 */
	private array $events = array();

	/**
	 * Buffered audit records.
	 *
	 * @var list<AuditRecord>
	 */
	private array $audit = array();

	/**
	 * Buffer lengths at the start of each open level.
	 *
	 * @var list<array{int, int}>
	 */
	private array $marks = array();

	/**
	 * Opens a level.
	 */
	public function begin(): void {
		$this->marks[] = array( count( $this->events ), count( $this->audit ) );
	}

	/**
	 * Closes a level, keeping what it recorded.
	 */
	public function commit(): void {
		array_pop( $this->marks );
	}

	/**
	 * Closes a level, dropping what it recorded.
	 */
	public function rollback(): void {
		$mark = array_pop( $this->marks );
		if ( null !== $mark ) {
			$this->events = array_slice( $this->events, 0, $mark[0] );
			$this->audit  = array_slice( $this->audit, 0, $mark[1] );
		}
	}

	/**
	 * Whether a transaction is running.
	 */
	public function active(): bool {
		return array() !== $this->marks;
	}

	/**
	 * Buffers an event.
	 *
	 * @param DomainEvent $event Event.
	 * @throws LogicException When no transaction is running.
	 */
	public function addEvent( DomainEvent $event ): void {
		$this->requireActive();
		$this->events[] = $event;
	}

	/**
	 * Buffers an audit record.
	 *
	 * @param AuditRecord $record Record.
	 * @throws LogicException When no transaction is running.
	 */
	public function addAudit( AuditRecord $record ): void {
		$this->requireActive();
		$this->audit[] = $record;
	}

	/**
	 * Takes everything buffered and empties the buffers.
	 *
	 * @return array{list<DomainEvent>, list<AuditRecord>}
	 */
	public function drain(): array {
		$drained      = array( $this->events, $this->audit );
		$this->events = array();
		$this->audit  = array();

		return $drained;
	}

	/**
	 * Refuses recording outside a transaction.
	 *
	 * @throws LogicException When no transaction is running.
	 */
	private function requireActive(): void {
		if ( ! $this->active() ) {
			throw new LogicException( 'Events and audit entries are recorded inside TransactionRunner::run().' );
		}
	}
}
