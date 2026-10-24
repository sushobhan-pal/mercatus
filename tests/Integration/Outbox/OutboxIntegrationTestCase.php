<?php
/**
 * Base for the outbox integration tests.
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Integration\Outbox;

use DateTimeImmutable;
use Mercatus\Core\Contracts\Events\EventSubscriber;
use Mercatus\Core\Infrastructure\Audit\AuditStore;
use Mercatus\Core\Infrastructure\Audit\BufferedAuditLog;
use Mercatus\Core\Infrastructure\Database\UnitOfWork;
use Mercatus\Core\Infrastructure\Database\WpdbTransactionRunner;
use Mercatus\Core\Infrastructure\Outbox\BulkEventCoalescer;
use Mercatus\Core\Infrastructure\Outbox\EventDispatcher;
use Mercatus\Core\Infrastructure\Outbox\EventPayloadSerializer;
use Mercatus\Core\Infrastructure\Outbox\OutboxEventPublisher;
use Mercatus\Core\Infrastructure\Outbox\OutboxProcessor;
use Mercatus\Core\Infrastructure\Outbox\OutboxStore;
use Mercatus\Core\Infrastructure\Outbox\OutboxWorker;
use Mercatus\Core\Infrastructure\Outbox\RetryBackoffPolicy;
use Mercatus\Core\Kernel\WordPressExecutionContext;
use Mercatus\Core\Shared\Actor\ActorRef;
use Mercatus\Core\Shared\Events\DomainEvent;
use Mercatus\Core\Shared\Events\EventLane;
use Mercatus\Core\Tests\Fixtures\Clock\FrozenClock;
use WP_UnitTestCase;

/**
 * Real outbox and audit tables (created by the bootstrap's migration), with the transaction runner using
 * savepoints because each test already runs inside the WordPress test suite's transaction.
 */
abstract class OutboxIntegrationTestCase extends WP_UnitTestCase {

	protected FrozenClock $clock;

	protected WordPressExecutionContext $context;

	protected OutboxStore $store;

	protected WpdbTransactionRunner $transactions;

	protected OutboxEventPublisher $events;

	protected BufferedAuditLog $audit;

	protected OutboxWorker $worker;

	/**
	 * Subscribers the dispatcher hands events to; tests set them before anything is dispatched.
	 *
	 * @var list<EventSubscriber>
	 */
	protected array $subscribers = array();

	public function set_up(): void {
		global $wpdb;
		parent::set_up();
		$this->clock   = new FrozenClock();
		$this->context = new WordPressExecutionContext( $this->clock );
		$unit          = new UnitOfWork();
		$this->store   = new OutboxStore( $wpdb, new EventPayloadSerializer() );
		$processor     = new OutboxProcessor( $this->store, new EventDispatcher( fn(): array => $this->subscribers ), new RetryBackoffPolicy( static fn( int $min ): int => $min ), new BulkEventCoalescer(), $this->clock );

		$this->transactions = new WpdbTransactionRunner( $wpdb, $unit, $this->store, new AuditStore( $wpdb ), $processor, $this->clock, true );
		$this->events       = new OutboxEventPublisher( $unit, $this->context, $this->clock );
		$this->audit        = new BufferedAuditLog( $unit, $this->context, $this->clock );
		$this->worker       = new OutboxWorker( $this->store, $processor, $this->clock );
	}

	/**
	 * Runs work that is expected to fail with a RuntimeException.
	 *
	 * @param callable(): mixed $work Work.
	 */
	protected function swallow( callable $work ): void {
		try {
			$work();
			$this->fail( 'The work was expected to fail.' );
		} catch ( \RuntimeException $error ) {
			$this->assertNotSame( '', $error->getMessage() );
		}
	}

	/**
	 * Outbox status by event ID.
	 *
	 * @param string $event_id Event ID.
	 */
	protected function status( string $event_id ): ?string {
		global $wpdb;
		$status = $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM %i WHERE event_uuid = %s', $wpdb->prefix . 'mercatus_events_outbox', $event_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test assertion.

		return null === $status ? null : (string) $status;
	}

	/**
	 * Audit rows for a resource.
	 *
	 * @param string $resource_id Resource ID.
	 */
	protected function auditRows( string $resource_id ): int {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE resource_id = %s', $wpdb->prefix . 'mercatus_audit_log', $resource_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test assertion.
	}

	/**
	 * An event built directly, for writing to the store without a transaction.
	 *
	 * @param string    $id   Event ID suffix.
	 * @param EventLane $lane Lane.
	 */
	protected function event( string $id, EventLane $lane = EventLane::Critical ): DomainEvent {
		return new DomainEvent( 'store.profile.updated', 'test-' . $id, new DateTimeImmutable(), 'store', $id, 1, ActorRef::system(), 'req_test', array( 'n' => $id ), 1, $lane );
	}
}
