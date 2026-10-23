<?php
/**
 * The audit log table (DATA-2 audit_log, KER-9, KER-15).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Infrastructure\Audit;

use DateTimeImmutable;
use Mercatus\Core\Infrastructure\Database\PreparesQueries;
use Mercatus\Core\Infrastructure\Outbox\EventPayloadSerializer;
use wpdb;

/**
 * Append-only; rows are only ever deleted by the optional age-based purge (KER-15).
 */
final class AuditStore {

	use PreparesQueries;

	/**
	 * Creates the store.
	 *
	 * @param wpdb $db WordPress database.
	 */
	public function __construct( private wpdb $db ) {}

	/**
	 * Writes records in one statement; call inside the command's transaction.
	 *
	 * @param list<AuditRecord> $records Records.
	 */
	public function insertMany( array $records ): void {
		if ( array() === $records ) {
			return;
		}
		$rows = array();
		foreach ( $records as $record ) {
			$rows[] = self::prepared(
				$this->db,
				'(%s, %d, %s, %s, %s, %s, %s, %s)',
				$record->actor->type,
				$record->actor->id,
				$record->action,
				$record->resourceType,
				$record->resourceId,
				$record->requestId,
				(string) wp_json_encode( $record->changes ),
				EventPayloadSerializer::format( $record->createdAt )
			);
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- each row is prepared above; the table name is fixed.
		$this->db->query( "INSERT INTO {$this->table()} (actor_type, actor_id, action, resource_type, resource_id, request_id, changes, created_at) VALUES " . implode( ', ', $rows ) );
	}

	/**
	 * Deletes entries older than a time, in one bounded batch.
	 *
	 * @param DateTimeImmutable $before Cut-off.
	 * @param int               $limit  Most rows to delete.
	 */
	public function purge( DateTimeImmutable $before, int $limit = 1000 ): int {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- retention clean-up (KER-15).
		$deleted = $this->db->query( self::prepared( $this->db, 'DELETE FROM %i WHERE created_at < %s LIMIT %d', $this->table(), EventPayloadSerializer::format( $before ), $limit ) );

		return is_int( $deleted ) ? $deleted : 0;
	}

	/**
	 * Full table name.
	 */
	private function table(): string {
		return $this->db->prefix . 'mercatus_audit_log';
	}
}
