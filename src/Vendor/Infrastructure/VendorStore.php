<?php
/**
 * The vendors table (DATA-2, VEN-1).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Vendor\Infrastructure;

use DateTimeImmutable;
use Mercatus\Core\Infrastructure\Database\PreparesQueries;
use Mercatus\Core\Infrastructure\Outbox\EventPayloadSerializer;
use Mercatus\Core\Shared\Http\ListRequest;
use Mercatus\Core\Vendor\Domain\Vendor;
use wpdb;

/**
 * One row per approved vendor. The claim source reads it by user_id, one indexed query (VEN-4).
 */
final class VendorStore {

	use PreparesQueries;

	private const COLUMNS = 'id, user_id, application_id, status, reason, created_at, updated_at, version';

	/**
	 * Creates the store.
	 *
	 * @param wpdb $db WordPress database.
	 */
	public function __construct( private wpdb $db ) {}

	/**
	 * Creates an active vendor and returns its ID.
	 *
	 * @param int               $user_id        WordPress user.
	 * @param int               $application_id The approved attempt.
	 * @param DateTimeImmutable $now            Current time.
	 */
	public function insert( int $user_id, int $application_id, DateTimeImmutable $now ): int {
		$time = EventPayloadSerializer::format( $now );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the module's own table.
		$this->db->query( self::prepared( $this->db, "INSERT INTO %i (user_id, application_id, status, created_at, updated_at) VALUES (%d, %d, 'active', %s, %s)", $this->table(), $user_id, $application_id, $time, $time ) );

		return (int) $this->db->insert_id;
	}

	/**
	 * A vendor by ID.
	 *
	 * @param int $id ID.
	 */
	public function byId( int $id ): ?Vendor {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed column list.
		$row = $this->db->get_row( self::prepared( $this->db, 'SELECT ' . self::COLUMNS . ' FROM %i WHERE id = %d', $this->table(), $id ), ARRAY_A );

		return is_array( $row ) ? self::vendor( $row ) : null;
	}

	/**
	 * A user's vendor, if they are one.
	 *
	 * @param int $user_id WordPress user.
	 */
	public function byUserId( int $user_id ): ?Vendor {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed column list.
		$row = $this->db->get_row( self::prepared( $this->db, 'SELECT ' . self::COLUMNS . ' FROM %i WHERE user_id = %d', $this->table(), $user_id ), ARRAY_A );

		return is_array( $row ) ? self::vendor( $row ) : null;
	}

	/**
	 * Moves a vendor to a status, if it's still at the expected version.
	 *
	 * @param int               $id       ID.
	 * @param int               $expected Version the change was based on.
	 * @param string            $status   New status.
	 * @param string|null       $reason   Why, for a suspension.
	 * @param DateTimeImmutable $now      Current time.
	 */
	public function transition( int $id, int $expected, string $status, ?string $reason, DateTimeImmutable $now ): bool {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- optimistic update on the version (PLAT-9).
		$updated = $this->db->query( self::prepared( $this->db, "UPDATE %i SET status = %s, reason = NULLIF( %s, '' ), updated_at = %s, version = version + 1 WHERE id = %d AND version = %d", $this->table(), $status, $reason ?? '', EventPayloadSerializer::format( $now ), $id, $expected ) );

		return 1 === $updated;
	}

	/**
	 * One page of vendors, with one extra row to tell whether more follow.
	 *
	 * @param ListRequest $request Validated list request; sorted by id.
	 * @return list<array{id: int, version: int, vendor: Vendor}>
	 */
	public function page( ListRequest $request ): array {
		$descending = 'desc' === $request->direction;
		$status     = $request->filters['status'] ?? '';
		$after      = is_int( $request->afterValue ) ? $request->afterValue : ( $descending ? PHP_INT_MAX : 0 );
		$query      = $descending
			? 'SELECT ' . self::COLUMNS . " FROM %i WHERE ( %s = '' OR status = %s ) AND id < %d ORDER BY id DESC LIMIT %d"
			: 'SELECT ' . self::COLUMNS . " FROM %i WHERE ( %s = '' OR status = %s ) AND id > %d ORDER BY id ASC LIMIT %d";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- prepared by the helper; fixed SQL.
		$rows = $this->db->get_results( self::prepared( $this->db, $query, $this->table(), $status, $status, $after, $request->perPage + 1 ), ARRAY_A );

		$page = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$vendor = self::vendor( $row );
			$page[] = array(
				'id'      => $vendor->id,
				'version' => $vendor->version,
				'vendor'  => $vendor,
			);
		}

		return $page;
	}

	/**
	 * A vendor from a row.
	 *
	 * @param array<string, mixed> $row Row.
	 */
	private static function vendor( array $row ): Vendor {
		return new Vendor(
			(int) $row['id'],
			(int) $row['user_id'],
			(int) $row['application_id'],
			(string) $row['status'],
			null === $row['reason'] ? null : (string) $row['reason'],
			(string) $row['created_at'],
			(string) $row['updated_at'],
			(int) $row['version']
		);
	}

	/**
	 * Full table name.
	 */
	private function table(): string {
		return $this->db->prefix . 'mercatus_vendors';
	}
}
