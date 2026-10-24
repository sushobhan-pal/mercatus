<?php
/**
 * The vendor applications table (DATA-2, VEN-11).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Vendor\Infrastructure;

use DateTimeImmutable;
use Mercatus\Core\Infrastructure\Database\PreparesQueries;
use Mercatus\Core\Infrastructure\Outbox\EventPayloadSerializer;
use Mercatus\Core\Shared\Http\ListRequest;
use Mercatus\Core\Vendor\Domain\VendorApplication;
use wpdb;

/**
 * Attempts are inserted, decided once, and never overwritten; a user's history is one indexed query.
 */
final class ApplicationStore {

	use PreparesQueries;

	private const COLUMNS = 'id, user_id, attempt, status, store_name, answers, reason, decided_by, decided_at, created_at, updated_at, version';

	/**
	 * Creates the store.
	 *
	 * @param wpdb $db WordPress database.
	 */
	public function __construct( private wpdb $db ) {}

	/**
	 * Inserts an attempt and returns its ID.
	 *
	 * @param int                                                                         $user_id    Applicant.
	 * @param int                                                                         $attempt    Attempt number.
	 * @param string                                                                      $store_name Requested store name.
	 * @param list<array{id: string, label: string, type: string, answer: string|bool|null}> $answers    Snapshot.
	 * @param DateTimeImmutable                                                           $now        Current time.
	 */
	public function insert( int $user_id, int $attempt, string $store_name, array $answers, DateTimeImmutable $now ): int {
		$time = EventPayloadSerializer::format( $now );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the module's own table.
		$this->db->query( self::prepared( $this->db, "INSERT INTO %i (user_id, attempt, status, store_name, answers, created_at, updated_at) VALUES (%d, %d, 'pending', %s, %s, %s, %s)", $this->table(), $user_id, $attempt, $store_name, (string) wp_json_encode( $answers ), $time, $time ) );

		return (int) $this->db->insert_id;
	}

	/**
	 * An attempt by ID.
	 *
	 * @param int $id ID.
	 */
	public function byId( int $id ): ?VendorApplication {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed column list.
		$row = $this->db->get_row( self::prepared( $this->db, 'SELECT ' . self::COLUMNS . ' FROM %i WHERE id = %d', $this->table(), $id ), ARRAY_A );

		return is_array( $row ) ? self::application( $row ) : null;
	}

	/**
	 * A user's attempts, oldest first.
	 *
	 * @param int $user_id Applicant.
	 * @return list<VendorApplication>
	 */
	public function history( int $user_id ): array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed column list.
		$rows = $this->db->get_results( self::prepared( $this->db, 'SELECT ' . self::COLUMNS . ' FROM %i WHERE user_id = %d ORDER BY attempt ASC', $this->table(), $user_id ), ARRAY_A );

		return array_map( array( self::class, 'application' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Decides a pending attempt, if it's still at the expected version.
	 *
	 * @param int               $id         ID.
	 * @param int               $expected   Version the decision was based on.
	 * @param string            $status     approved, rejected or withdrawn.
	 * @param string|null       $reason     Why it was rejected.
	 * @param int               $decided_by Who decided; 0 for the system.
	 * @param DateTimeImmutable $now        Current time.
	 */
	public function decide( int $id, int $expected, string $status, ?string $reason, int $decided_by, DateTimeImmutable $now ): bool {
		$time = EventPayloadSerializer::format( $now );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- optimistic update on the version (PLAT-9).
		$updated = $this->db->query( self::prepared( $this->db, "UPDATE %i SET status = %s, reason = NULLIF( %s, '' ), decided_by = %d, decided_at = %s, updated_at = %s, version = version + 1 WHERE id = %d AND version = %d AND status = 'pending'", $this->table(), $status, $reason ?? '', $decided_by, $time, $time, $id, $expected ) );

		return 1 === $updated;
	}

	/**
	 * One page of the review queue, with one extra row to tell whether more follow.
	 *
	 * @param ListRequest $request Validated list request; sorted by id.
	 * @return list<array{id: int, version: int, application: VendorApplication}>
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
			$application = self::application( $row );
			$page[]      = array(
				'id'          => $application->id,
				'version'     => $application->version,
				'application' => $application,
			);
		}

		return $page;
	}

	/**
	 * An attempt from a row.
	 *
	 * @param array<string, mixed> $row Row.
	 */
	private static function application( array $row ): VendorApplication {
		$answers = json_decode( is_string( $row['answers'] ) ? $row['answers'] : '[]', true );

		return new VendorApplication(
			(int) $row['id'],
			(int) $row['user_id'],
			(int) $row['attempt'],
			(string) $row['status'],
			(string) $row['store_name'],
			self::answers( $answers ),
			null === $row['reason'] ? null : (string) $row['reason'],
			null === $row['decided_by'] ? null : (int) $row['decided_by'],
			null === $row['decided_at'] ? null : (string) $row['decided_at'],
			(string) $row['created_at'],
			(string) $row['updated_at'],
			(int) $row['version']
		);
	}

	/**
	 * The stored snapshot, keeping only well-formed entries.
	 *
	 * @param mixed $answers Decoded JSON.
	 * @return list<array{id: string, label: string, type: string, answer: string|bool|null}>
	 */
	private static function answers( mixed $answers ): array {
		$snapshot = array();
		foreach ( is_array( $answers ) ? $answers : array() as $entry ) {
			if ( is_array( $entry ) && is_string( $entry['id'] ?? null ) && is_string( $entry['label'] ?? null ) && is_string( $entry['type'] ?? null ) ) {
				$answer     = $entry['answer'] ?? null;
				$snapshot[] = array(
					'id'     => $entry['id'],
					'label'  => $entry['label'],
					'type'   => $entry['type'],
					'answer' => is_string( $answer ) || is_bool( $answer ) ? $answer : null,
				);
			}
		}

		return $snapshot;
	}

	/**
	 * Full table name.
	 */
	private function table(): string {
		return $this->db->prefix . 'mercatus_vendor_applications';
	}
}
