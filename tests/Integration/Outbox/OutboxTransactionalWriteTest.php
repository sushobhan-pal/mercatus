<?php
/**
 * State, audit and outbox commit together (TEST-3 row 3, API-3, ARCH-10).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Integration\Outbox;

use LogicException;
use RuntimeException;

/**
 * A command's own rows, its audit entry and its events land in one transaction or not at all; an inner
 * rollback drops only the inner work.
 */
final class OutboxTransactionalWriteTest extends OutboxIntegrationTestCase {

	public function set_up(): void {
		global $wpdb;
		parent::set_up();
		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}mercatus_test_state ( id BIGINT UNSIGNED NOT NULL, PRIMARY KEY (id) )" ); // phpcs:ignore WordPress.DB -- temporary fixture table.
	}

	public function tear_down(): void {
		global $wpdb;
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mercatus_test_state" ); // phpcs:ignore WordPress.DB -- fixture clean-up.
		parent::tear_down();
	}

	public function testStateAuditAndEventsCommitTogether(): void {
		$event = $this->transactions->run( fn() => $this->command( 41 ) );

		$this->assertSame( 1, $this->stateRows( 41 ) );
		$this->assertSame( 1, $this->auditRows( '41' ) );
		$this->assertSame( 'processed', $this->status( $event ) );
	}

	public function testAFailureLeavesNoTrace(): void {
		$event = '';
		$this->swallow(
			fn() => $this->transactions->run(
				function () use ( &$event ): void {
					$event = $this->command( 42 );
					throw new RuntimeException( 'The command failed.' );
				}
			)
		);

		$this->assertSame( 0, $this->stateRows( 42 ) );
		$this->assertSame( 0, $this->auditRows( '42' ) );
		$this->assertNull( $this->status( $event ) );
	}

	public function testAnInnerRollbackDropsOnlyTheInnerWork(): void {
		$inner = '';
		$outer = $this->transactions->run(
			function () use ( &$inner ): string {
				$outer = $this->command( 43 );
				// The inner step fails; the outer command carries on.
				$this->swallow(
					fn() => $this->transactions->run(
						function () use ( &$inner ): void {
							$inner = $this->command( 44 );
							throw new RuntimeException( 'The inner step failed.' );
						}
					)
				);

				return $outer;
			}
		);

		$this->assertSame( 1, $this->stateRows( 43 ) );
		$this->assertSame( 0, $this->stateRows( 44 ) );
		$this->assertSame( 'processed', $this->status( $outer ) );
		$this->assertNull( $this->status( $inner ) );
		$this->assertSame( 0, $this->auditRows( '44' ) );
	}

	public function testRecordingOutsideATransactionIsRefused(): void {
		$this->expectException( LogicException::class );
		$this->events->publish( 'store.profile.updated', 'store', 1, 1, array() );
	}

	/**
	 * A command: writes its state, an audit entry and an event.
	 *
	 * @param int $id State row ID.
	 * @return string The event's ID.
	 */
	private function command( int $id ): string {
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'mercatus_test_state', array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- fixture state.
		$this->audit->record( 'store.updated', 'store', $id, array( 'status' => 'active' ) );

		return $this->events->publish( 'store.profile.updated', 'store', $id, 1, array( 'id' => $id ) )->eventId;
	}

	/**
	 * State rows with an ID.
	 *
	 * @param int $id ID.
	 */
	private function stateRows( int $id ): int {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE id = %d', $wpdb->prefix . 'mercatus_test_state', $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test assertion.
	}
}
