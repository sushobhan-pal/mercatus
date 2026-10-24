<?php
/**
 * The API clients table (DATA-2).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Identity\Infrastructure;

use DateTimeImmutable;
use Mercatus\Core\Identity\Domain\ApiClient;
use Mercatus\Core\Infrastructure\Database\PreparesQueries;
use Mercatus\Core\Infrastructure\Outbox\EventPayloadSerializer;
use Mercatus\Core\Shared\Http\ListRequest;
use wpdb;

/**
 * Lookups by ID and by the hash of the application ID (the unique key); changes check the version (PLAT-9).
 */
final class ApiClientStore {

	use PreparesQueries;

	private const COLUMNS = 'id, name, actor_type, actor_id, scopes, ip_allow_list, password_lifetime_days, status, last_used_at, created_at, updated_at, version';

	/**
	 * Creates the store.
	 *
	 * @param wpdb $db WordPress database.
	 */
	public function __construct( private wpdb $db ) {}

	/**
	 * Registers a client and returns its ID.
	 *
	 * @param string            $hash         SHA-256 of the application ID.
	 * @param string            $name         Name.
	 * @param int|null          $user_id      The integration's user; null for an app.
	 * @param list<string>      $scopes       Scopes.
	 * @param list<string>      $ips          IP allow-list.
	 * @param int               $lifetime     Password lifetime in days.
	 * @param int               $created_by   The administrator who registered it.
	 * @param DateTimeImmutable $now          Current time.
	 */
	public function insert( string $hash, string $name, ?int $user_id, array $scopes, array $ips, int $lifetime, int $created_by, DateTimeImmutable $now ): int {
		$time = EventPayloadSerializer::format( $now );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the module's own table.
		$this->db->query( self::prepared( $this->db, "INSERT INTO %i (client_key_hash, name, actor_type, actor_id, scopes, ip_allow_list, password_lifetime_days, status, created_by, created_at, updated_at) VALUES (%s, %s, %s, %d, %s, %s, %d, 'active', %d, %s, %s)", $this->table(), $hash, $name, null === $user_id ? 'app' : 'user', $user_id ?? 0, (string) wp_json_encode( $scopes ), (string) wp_json_encode( $ips ), $lifetime, $created_by, $time, $time ) );

		return (int) $this->db->insert_id;
	}

	/**
	 * A client by ID.
	 *
	 * @param int $id ID.
	 */
	public function byId( int $id ): ?ApiClient {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed column list.
		$row = $this->db->get_row( self::prepared( $this->db, 'SELECT ' . self::COLUMNS . ' FROM %i WHERE id = %d', $this->table(), $id ), ARRAY_A );

		return is_array( $row ) ? self::client( $row ) : null;
	}

	/**
	 * A client by the hash of its application ID.
	 *
	 * @param string $hash SHA-256 of the application ID.
	 */
	public function byHash( string $hash ): ?ApiClient {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed column list.
		$row = $this->db->get_row( self::prepared( $this->db, 'SELECT ' . self::COLUMNS . ' FROM %i WHERE client_key_hash = %s', $this->table(), $hash ), ARRAY_A );

		return is_array( $row ) ? self::client( $row ) : null;
	}

	/**
	 * Changes a client if it's still at the expected version.
	 *
	 * @param int               $id       ID.
	 * @param int               $expected Version the change was based on.
	 * @param string            $name     Name.
	 * @param list<string>      $scopes   Scopes.
	 * @param list<string>      $ips      IP allow-list.
	 * @param int               $lifetime Password lifetime in days.
	 * @param string            $status   Status.
	 * @param DateTimeImmutable $now      Current time.
	 */
	public function update( int $id, int $expected, string $name, array $scopes, array $ips, int $lifetime, string $status, DateTimeImmutable $now ): bool {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- optimistic update on the version (PLAT-9).
		$updated = $this->db->query( self::prepared( $this->db, 'UPDATE %i SET name = %s, scopes = %s, ip_allow_list = %s, password_lifetime_days = %d, status = %s, updated_at = %s, version = version + 1 WHERE id = %d AND version = %d', $this->table(), $name, (string) wp_json_encode( $scopes ), (string) wp_json_encode( $ips ), $lifetime, $status, EventPayloadSerializer::format( $now ), $id, $expected ) );

		return 1 === $updated;
	}

	/**
	 * Records that a request came through a client, at most once an hour.
	 *
	 * @param int               $id  ID.
	 * @param DateTimeImmutable $now Current time.
	 */
	public function touch( int $id, DateTimeImmutable $now ): void {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- usage time, throttled to an hour.
		$this->db->query( self::prepared( $this->db, 'UPDATE %i SET last_used_at = %s WHERE id = %d AND ( last_used_at IS NULL OR last_used_at < %s )', $this->table(), EventPayloadSerializer::format( $now ), $id, EventPayloadSerializer::format( $now->modify( '-1 hour' ) ) ) );
	}

	/**
	 * One page of clients, with one extra row to tell whether more follow.
	 *
	 * @param ListRequest $request Validated list request; sorted by id.
	 * @return list<array<string, mixed>>
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
			$client = self::client( $row );
			$page[] = array(
				'id'      => $client->id,
				'version' => $client->version,
				'client'  => $client,
			);
		}

		return $page;
	}

	/**
	 * A client from a row.
	 *
	 * @param array<string, mixed> $row Row.
	 */
	private static function client( array $row ): ApiClient {
		return new ApiClient(
			(int) $row['id'],
			(string) $row['name'],
			'user' === $row['actor_type'] ? (int) $row['actor_id'] : null,
			self::strings( $row['scopes'] ),
			self::strings( $row['ip_allow_list'] ),
			(int) $row['password_lifetime_days'],
			(string) $row['status'],
			null === $row['last_used_at'] ? null : (string) $row['last_used_at'],
			(string) $row['created_at'],
			(string) $row['updated_at'],
			(int) $row['version']
		);
	}

	/**
	 * A JSON column as a list of strings.
	 *
	 * @param mixed $json JSON.
	 * @return list<string>
	 */
	private static function strings( mixed $json ): array {
		$values = json_decode( is_string( $json ) ? $json : '[]', true );

		return is_array( $values ) ? array_values( array_filter( $values, 'is_string' ) ) : array();
	}

	/**
	 * Full table name.
	 */
	private function table(): string {
		return $this->db->prefix . 'mercatus_api_clients';
	}
}
