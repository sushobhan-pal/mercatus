<?php
/**
 * The outbox table (DATA-2 events_outbox, API-3, KER-7).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Infrastructure\Outbox;

use DateInterval;
use DateTimeImmutable;
use Mercatus\Core\Infrastructure\Database\PreparesQueries;
use Mercatus\Core\Shared\Events\DomainEvent;
use Mercatus\Core\Shared\Events\EventLane;
use wpdb;

/**
 * Pending rows are claimed with a token and a lease: a claim marks rows with claimed_by and lease_until, so
 * parallel workers never take the same row, and a row whose lease ran out (its worker died) can be claimed
 * again. Claims follow idx_outbox_poll.
 */
final class OutboxStore {

	use PreparesQueries;

	/**
	 * Creates the store.
	 *
	 * @param wpdb                   $db         WordPress database.
	 * @param EventPayloadSerializer $serializer Row format.
	 */
	public function __construct( private wpdb $db, private EventPayloadSerializer $serializer ) {}

	/**
	 * Writes events in one statement; call inside the command's transaction.
	 *
	 * @param list<DomainEvent>  $events Events.
	 * @param DateTimeImmutable  $now    Current time; events are available from now.
	 */
	public function insertMany( array $events, DateTimeImmutable $now ): void {
		if ( array() === $events ) {
			return;
		}
		$rows = array();
		foreach ( $events as $event ) {
			$row    = $this->serializer->toRow( $event, $now );
			$rows[] = self::prepared(
				$this->db,
				'(%s, %s, %s, NULLIF(%s, \'\'), %s, %s, %d, %s, %d, %s, %d, %s, %s, %s)',
				(string) $row['event_uuid'],
				(string) $row['event_name'],
				(string) $row['lane'],
				(string) $row['coalesce_key'],
				(string) $row['aggregate_type'],
				(string) $row['aggregate_id'],
				(int) $row['aggregate_version'],
				(string) $row['actor_type'],
				(int) $row['actor_id'],
				(string) $row['correlation_id'],
				(int) $row['schema_version'],
				(string) $row['payload'],
				(string) $row['occurred_at'],
				(string) $row['available_at']
			);
		}
		$columns = '(event_uuid, event_name, lane, coalesce_key, aggregate_type, aggregate_id, aggregate_version, actor_type, actor_id, correlation_id, schema_version, payload, occurred_at, available_at)';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- each row is prepared above; the table name is fixed.
		$this->db->query( "INSERT INTO {$this->table()} {$columns} VALUES " . implode( ', ', $rows ) );
	}

	/**
	 * Claims the next available events of a lane.
	 *
	 * @param EventLane         $lane          Lane.
	 * @param string            $token         Unique claim token.
	 * @param int               $limit         Most events to claim.
	 * @param DateTimeImmutable $now           Current time.
	 * @param int               $lease_seconds How long the claim lasts.
	 * @return list<OutboxRecord>
	 */
	public function claim( EventLane $lane, string $token, int $limit, DateTimeImmutable $now, int $lease_seconds ): array {
		$time = EventPayloadSerializer::format( $now );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- claims rows with an atomic update.
		$updated = $this->db->query( self::prepared( $this->db, "UPDATE %i SET claimed_by = %s, lease_until = %s WHERE lane = %s AND status = 'pending' AND available_at <= %s AND ( lease_until IS NULL OR lease_until < %s ) ORDER BY id LIMIT %d", $this->table(), $token, EventPayloadSerializer::format( $now->add( new DateInterval( 'PT' . $lease_seconds . 'S' ) ) ), $lane->value, $time, $time, $limit ) );

		return $this->claimed( $token, $updated );
	}

	/**
	 * Claims specific events just written, so the committing request can handle them itself.
	 *
	 * @param list<string>      $uuids         Event IDs.
	 * @param string            $token         Unique claim token.
	 * @param DateTimeImmutable $now           Current time.
	 * @param int               $lease_seconds How long the claim lasts.
	 * @return list<OutboxRecord>
	 */
	public function claimEvents( array $uuids, string $token, DateTimeImmutable $now, int $lease_seconds ): array {
		if ( array() === $uuids ) {
			return array();
		}
		$list = implode( ', ', array_map( fn( string $uuid ): string => self::prepared( $this->db, '%s', $uuid ), $uuids ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- the list is prepared value by value.
		$updated = $this->db->query( self::prepared( $this->db, "UPDATE %i SET claimed_by = %s, lease_until = %s WHERE status = 'pending' AND claimed_by IS NULL AND event_uuid IN ( ", $this->table(), $token, EventPayloadSerializer::format( $now->add( new DateInterval( 'PT' . $lease_seconds . 'S' ) ) ) ) . $list . ' )' );

		return $this->claimed( $token, $updated );
	}

	/**
	 * Marks claimed events handled or superseded.
	 *
	 * @param list<int>         $ids    Row IDs.
	 * @param string            $token  Claim token; rows claimed by someone else are left alone.
	 * @param DateTimeImmutable $now    Current time.
	 * @param string            $status processed or superseded.
	 */
	public function finish( array $ids, string $token, DateTimeImmutable $now, string $status = 'processed' ): void {
		if ( array() === $ids ) {
			return;
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- the ID list holds integers only.
		$this->db->query( self::prepared( $this->db, 'UPDATE %i SET status = %s, processed_at = %s, claimed_by = NULL, lease_until = NULL WHERE claimed_by = %s AND id IN ( ', $this->table(), $status, EventPayloadSerializer::format( $now ), $token ) . self::idList( $ids ) . ' )' );
	}

	/**
	 * Releases a claimed event that failed: back to pending later, or failed for good.
	 *
	 * @param OutboxRecord           $record       Record.
	 * @param string                 $token        Claim token.
	 * @param int                    $attempts     Failed attempts including this one.
	 * @param string                 $error_code   Short error code for the health report.
	 * @param DateTimeImmutable|null $available_at When to retry; null fails it for good.
	 */
	public function release( OutboxRecord $record, string $token, int $attempts, string $error_code, ?DateTimeImmutable $available_at ): void {
		$status = null === $available_at ? 'failed' : 'pending';
		$when   = null === $available_at ? '' : EventPayloadSerializer::format( $available_at );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- releases a claimed row.
		$this->db->query( self::prepared( $this->db, "UPDATE %i SET status = %s, attempts = %d, last_error_code = %s, available_at = IF( %s = '', available_at, %s ), claimed_by = NULL, lease_until = NULL WHERE id = %d AND claimed_by = %s", $this->table(), $status, $attempts, substr( $error_code, 0, 191 ), $when, $when, $record->id, $token ) );
	}

	/**
	 * Numbers for the health report.
	 *
	 * @return array{pending: int, failed: int, oldest_pending: string|null}
	 */
	public function health(): array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- health report.
		$row = $this->db->get_row( self::prepared( $this->db, "SELECT SUM( status = 'pending' ) AS pending, SUM( status = 'failed' ) AS failed, MIN( IF( status = 'pending', occurred_at, NULL ) ) AS oldest FROM %i WHERE status IN ( 'pending', 'failed' )", $this->table() ) );

		return array(
			'pending'        => is_object( $row ) ? (int) $row->pending : 0,
			'failed'         => is_object( $row ) ? (int) $row->failed : 0,
			'oldest_pending' => is_object( $row ) && null !== $row->oldest ? (string) $row->oldest : null,
		);
	}

	/**
	 * Deletes handled events processed before a time, in one bounded batch.
	 *
	 * @param DateTimeImmutable $before Cut-off.
	 * @param int               $limit  Most rows to delete.
	 */
	public function purge( DateTimeImmutable $before, int $limit = 1000 ): int {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- retention clean-up.
		$deleted = $this->db->query( self::prepared( $this->db, "DELETE FROM %i WHERE status IN ( 'processed', 'superseded' ) AND processed_at < %s LIMIT %d", $this->table(), EventPayloadSerializer::format( $before ), $limit ) );

		return is_int( $deleted ) ? $deleted : 0;
	}

	/**
	 * Rows a token claimed, oldest first. A claim that matched no rows reads nothing, so an idle poll costs one
	 * query per lane.
	 *
	 * @param string   $token   Claim token.
	 * @param int|bool $updated What the claiming update returned: rows changed, or false on error.
	 * @return list<OutboxRecord>
	 */
	private function claimed( string $token, int|bool $updated ): array {
		if ( 0 === $updated || false === $updated ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- reads the rows just claimed.
		$rows    = $this->db->get_results( self::prepared( $this->db, "SELECT * FROM %i WHERE claimed_by = %s AND status = 'pending' ORDER BY id", $this->table(), $token ), ARRAY_A );
		$records = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$records[] = new OutboxRecord( (int) $row['id'], $this->serializer->fromRow( $row ), (int) $row['attempts'] );
		}

		return $records;
	}

	/**
	 * Full table name.
	 */
	private function table(): string {
		return $this->db->prefix . 'mercatus_events_outbox';
	}
}
