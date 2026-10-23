<?php
/**
 * Applied migrations and the migration lock (DATA-2 schema_versions, KER-5).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Infrastructure\Database;

use Mercatus\Core\Shared\Clock\Clock;
use RuntimeException;
use wpdb;

/**
 * One row per applied migration with its file checksum, plus one lock row (module "_lock", version 0) that
 * lets a single process migrate at a time. A lock older than ten minutes is treated as abandoned.
 */
final class SchemaVersionStore {

	private const LOCK_MODULE = '_lock';

	private const LOCK_TIMEOUT = 600;

	/**
	 * Creates the store.
	 *
	 * @param wpdb  $db    WordPress database.
	 * @param Clock $clock Current time.
	 */
	public function __construct( private wpdb $db, private Clock $clock ) {}

	/**
	 * Creates the table if it doesn't exist; the runner needs it before any migration can be recorded.
	 */
	public function install(): void {
		$table = $this->table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- fixed DDL.
		$this->db->query(
			"CREATE TABLE IF NOT EXISTS {$table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				module_id VARCHAR(64) NOT NULL,
				version INT UNSIGNED NOT NULL,
				checksum CHAR(64) NOT NULL,
				applied_at DATETIME(3) NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY module_version (module_id, version)
			) {$this->db->get_charset_collate()}"
		);
	}

	/**
	 * Applied migrations of a module.
	 *
	 * @param string $module_id Module ID.
	 * @return array<int, string> Checksum by version.
	 */
	public function applied( string $module_id ): array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the migration ledger is read only while migrating.
		$rows    = $this->db->get_results( $this->prepare( 'SELECT version, checksum FROM %i WHERE module_id = %s ORDER BY version', $this->table(), $module_id ) );
		$applied = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$applied[ (int) $row->version ] = (string) $row->checksum;
		}

		return $applied;
	}

	/**
	 * Records an applied migration.
	 *
	 * @param string $module_id Module ID.
	 * @param int    $version   Migration version.
	 * @param string $checksum  SHA-256 of the migration file.
	 */
	public function record( string $module_id, int $version, string $checksum ): void {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the migration ledger.
		$this->db->insert(
			$this->table(),
			array(
				'module_id'  => $module_id,
				'version'    => $version,
				'checksum'   => $checksum,
				'applied_at' => $this->now(),
			)
		);
	}

	/**
	 * Takes the migration lock; false when another process holds it.
	 */
	public function acquireLock(): bool {
		$stale = gmdate( 'Y-m-d H:i:s', $this->clock->now()->getTimestamp() - self::LOCK_TIMEOUT );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- removes an abandoned lock.
		$this->db->query( $this->prepare( 'DELETE FROM %i WHERE module_id = %s AND applied_at < %s', $this->table(), self::LOCK_MODULE, $stale ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the unique key makes this insert the lock.
		$inserted = $this->db->query( $this->prepare( 'INSERT IGNORE INTO %i (module_id, version, checksum, applied_at) VALUES (%s, 0, %s, %s)', $this->table(), self::LOCK_MODULE, str_repeat( '0', 64 ), $this->now() ) );

		return 1 === $inserted;
	}

	/**
	 * Releases the migration lock.
	 */
	public function releaseLock(): void {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- releases the lock.
		$this->db->delete( $this->table(), array( 'module_id' => self::LOCK_MODULE ) );
	}

	/**
	 * A prepared statement.
	 *
	 * @param literal-string   $query     Query with placeholders; always fixed SQL.
	 * @param int|string|float ...$values Values.
	 * @throws RuntimeException When WordPress can't prepare the query.
	 */
	private function prepare( string $query, int|string|float ...$values ): string {
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- this is the prepare call.
		$prepared = $this->db->prepare( $query, ...$values );
		if ( ! is_string( $prepared ) ) {
			throw new RuntimeException( 'A schema-version query could not be prepared.' );
		}

		return $prepared;
	}

	/**
	 * Full table name.
	 */
	private function table(): string {
		return $this->db->prefix . 'mercatus_schema_versions';
	}

	/**
	 * Current time as DATETIME(3) in UTC (DATA-1).
	 */
	private function now(): string {
		return $this->clock->now()->format( 'Y-m-d H:i:s.v' );
	}
}
