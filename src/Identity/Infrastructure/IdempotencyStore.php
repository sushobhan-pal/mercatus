<?php
/**
 * The idempotency table (DATA-2, PLAT-7).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Identity\Infrastructure;

use DateTimeImmutable;
use Mercatus\Core\Infrastructure\Database\PreparesQueries;
use Mercatus\Core\Infrastructure\Outbox\EventPayloadSerializer;
use wpdb;

/**
 * A row is inserted as processing before the command runs, so its unique key is the lock; no cache is
 * involved, with or without Redis (G-CACHE-5).
 */
final class IdempotencyStore {

	use PreparesQueries;

	/**
	 * Creates the store.
	 *
	 * @param wpdb $db WordPress database.
	 */
	public function __construct( private wpdb $db ) {}

	/**
	 * Claims a key; false when a row for it exists already.
	 *
	 * @param string            $fingerprint Actor fingerprint.
	 * @param string            $route       Method and path.
	 * @param string            $key         Idempotency-Key.
	 * @param string            $hash        Request hash.
	 * @param DateTimeImmutable $now         Current time.
	 * @param DateTimeImmutable $expires     When the row may be deleted.
	 */
	public function claim( string $fingerprint, string $route, string $key, string $hash, DateTimeImmutable $now, DateTimeImmutable $expires ): bool {
		$this->db->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the unique key is the lock (PLAT-7).
		$inserted = $this->db->query( self::prepared( $this->db, "INSERT IGNORE INTO %i (actor_fingerprint, route, idempotency_key, request_hash, status, created_at, expires_at) VALUES (%s, %s, %s, %s, 'processing', %s, %s)", $this->table(), $fingerprint, $route, $key, $hash, EventPayloadSerializer::format( $now ), EventPayloadSerializer::format( $expires ) ) );
		$this->db->suppress_errors( false );

		return 1 === $inserted;
	}

	/**
	 * The row for a key.
	 *
	 * @param string $fingerprint Actor fingerprint.
	 * @param string $route       Method and path.
	 * @param string $key         Idempotency-Key.
	 * @return array{id: int, request_hash: string, status: string, response_status: int|null, response_body: string|null}|null
	 */
	public function find( string $fingerprint, string $route, string $key ): ?array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- reads the claimed row.
		$row = $this->db->get_row( self::prepared( $this->db, 'SELECT id, request_hash, status, response_status, response_body FROM %i WHERE actor_fingerprint = %s AND route = %s AND idempotency_key = %s', $this->table(), $fingerprint, $route, $key ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return null;
		}

		return array(
			'id'              => (int) $row['id'],
			'request_hash'    => (string) $row['request_hash'],
			'status'          => (string) $row['status'],
			'response_status' => null === $row['response_status'] ? null : (int) $row['response_status'],
			'response_body'   => null === $row['response_body'] ? null : (string) $row['response_body'],
		);
	}

	/**
	 * Stores the response of a finished request.
	 *
	 * @param int    $id     Row ID.
	 * @param int    $status HTTP status.
	 * @param string $body   Stored response, as JSON.
	 */
	public function complete( int $id, int $status, string $body ): void {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- completes the claimed row.
		$this->db->query( self::prepared( $this->db, "UPDATE %i SET status = 'completed', response_status = %d, response_body = %s, version = version + 1 WHERE id = %d", $this->table(), $status, $body, $id ) );
	}

	/**
	 * Releases a key whose request failed unexpectedly, so the client can retry.
	 *
	 * @param int $id Row ID.
	 */
	public function release( int $id ): void {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- releases the claimed row.
		$this->db->query( self::prepared( $this->db, 'DELETE FROM %i WHERE id = %d', $this->table(), $id ) );
	}

	/**
	 * Deletes expired rows, in one bounded batch.
	 *
	 * @param DateTimeImmutable $now   Current time.
	 * @param int               $limit Most rows to delete.
	 */
	public function purge( DateTimeImmutable $now, int $limit = 1000 ): int {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- retention clean-up.
		$deleted = $this->db->query( self::prepared( $this->db, 'DELETE FROM %i WHERE expires_at < %s LIMIT %d', $this->table(), EventPayloadSerializer::format( $now ), $limit ) );

		return is_int( $deleted ) ? $deleted : 0;
	}

	/**
	 * Full table name.
	 */
	private function table(): string {
		return $this->db->prefix . 'mercatus_idempotency';
	}
}
