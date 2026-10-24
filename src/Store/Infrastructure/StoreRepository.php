<?php
/**
 * The stores table, with each store's default location (DATA-2, STO-4).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Store\Infrastructure;

use DateTimeImmutable;
use Mercatus\Core\Infrastructure\Database\PreparesQueries;
use Mercatus\Core\Infrastructure\Outbox\EventPayloadSerializer;
use Mercatus\Core\Shared\Http\ListRequest;
use Mercatus\Core\Store\Domain\Location;
use Mercatus\Core\Store\Domain\Store;
use Mercatus\Core\Store\Domain\StoreProfile;
use RuntimeException;
use wpdb;

/**
 * Every read joins the store's default location, so a store and its address cost one indexed query. Changes
 * are optimistic on `version` (PLAT-9).
 */
final class StoreRepository {

	use PreparesQueries;

	private const COLUMNS = 's.id, s.vendor_id, s.name, s.slug, s.tagline, s.description, s.contact_email, s.contact_phone, s.show_email, s.show_phone, s.policy_shipping, s.policy_returns, s.policy_privacy, s.logo_id, s.banner_id, s.visibility, s.status, s.created_at, s.updated_at, s.version, l.id AS l_id, l.type AS l_type, l.status AS l_status, l.address_1 AS l_address_1, l.address_2 AS l_address_2, l.city AS l_city, l.state AS l_state, l.postcode AS l_postcode, l.country AS l_country, l.latitude AS l_latitude, l.longitude AS l_longitude, l.geo_cell AS l_geo_cell, l.version AS l_version';

	/**
	 * How a store meets its default location; uses the locations' (vendor_id, status, is_default) index.
	 */
	private const JOIN = "l.vendor_id = s.vendor_id AND l.status = 'active' AND l.is_default = 1";

	/**
	 * Columns a change may set, with their placeholder.
	 */
	private const WRITABLE = array(
		'name'            => '%s',
		'slug'            => '%s',
		'tagline'         => '%s',
		'description'     => '%s',
		'contact_email'   => '%s',
		'contact_phone'   => '%s',
		'show_email'      => '%d',
		'show_phone'      => '%d',
		'policy_shipping' => '%s',
		'policy_returns'  => '%s',
		'policy_privacy'  => '%s',
		'visibility'      => '%s',
	);

	/**
	 * Creates the repository.
	 *
	 * @param wpdb $db WordPress database.
	 */
	public function __construct( private wpdb $db ) {}

	/**
	 * Creates a store and returns its ID.
	 *
	 * @param int               $vendor_id  Owner vendor.
	 * @param string            $name       Name.
	 * @param string            $slug       Free slug.
	 * @param string            $visibility public or hidden.
	 * @param DateTimeImmutable $now        Current time.
	 * @throws RuntimeException When the database refuses it, for example because the slug was taken meanwhile.
	 */
	public function insert( int $vendor_id, string $name, string $slug, string $visibility, DateTimeImmutable $now ): int {
		$time = EventPayloadSerializer::format( $now );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the module's own table.
		$inserted = $this->db->query( self::prepared( $this->db, "INSERT INTO %i (vendor_id, name, slug, visibility, status, created_at, updated_at) VALUES (%d, %s, %s, %s, 'active', %s, %s)", $this->stores(), $vendor_id, $name, $slug, $visibility, $time, $time ) );
		if ( 1 !== $inserted ) {
			throw new RuntimeException( 'The store could not be created.' );
		}

		return (int) $this->db->insert_id;
	}

	/**
	 * A store with its default location, by ID.
	 *
	 * @param int $id Store ID.
	 * @return array{0: Store, 1: Location|null}|null
	 */
	public function byId( int $id ): ?array {
		return $this->one( 's.id = %d', $id );
	}

	/**
	 * A vendor's store with its default location.
	 *
	 * @param int $vendor_id Vendor.
	 * @return array{0: Store, 1: Location|null}|null
	 */
	public function byVendorId( int $vendor_id ): ?array {
		return $this->one( 's.vendor_id = %d', $vendor_id );
	}

	/**
	 * A store with its default location, by slug.
	 *
	 * @param string $slug Slug.
	 * @return array{0: Store, 1: Location|null}|null
	 */
	public function bySlug( string $slug ): ?array {
		return $this->one( 's.slug = %s', $slug );
	}

	/**
	 * The slugs in use that start like a base: the base itself and base-n.
	 *
	 * @param string $base Base slug.
	 * @return list<string>
	 */
	public function slugsLike( string $base ): array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the module's own table.
		$slugs = $this->db->get_col( self::prepared( $this->db, 'SELECT slug FROM %i WHERE slug = %s OR slug LIKE %s', $this->stores(), $base, $this->db->esc_like( $base . '-' ) . '%' ) );

		return array_map( 'strval', $slugs );
	}

	/**
	 * Changes a store's columns, if it's still at the expected version.
	 *
	 * @param int                  $id       Store ID.
	 * @param int                  $expected Version the change was based on.
	 * @param array<string, mixed> $columns  New values by column (WRITABLE).
	 * @param DateTimeImmutable    $now      Current time.
	 */
	public function update( int $id, int $expected, array $columns, DateTimeImmutable $now ): bool {
		$sets   = array();
		$values = array();
		foreach ( array_intersect_key( $columns, self::WRITABLE ) as $column => $value ) {
			$sets[]   = $column . ' = ' . self::WRITABLE[ $column ];
			$values[] = is_bool( $value ) ? (int) $value : ( is_int( $value ) ? $value : (string) $value );
		}
		$sets[] = 'updated_at = %s';
		$query  = 'UPDATE %i SET ' . implode( ', ', $sets ) . ', version = version + 1 WHERE id = %d AND version = %d';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- columns come from WRITABLE; prepared by the helper.
		$updated = $this->db->query( self::prepared( $this->db, $query, $this->stores(), ...array_merge( $values, array( EventPayloadSerializer::format( $now ), $id, $expected ) ) ) );

		return 1 === $updated;
	}

	/**
	 * Sets a store's status, following its vendor (STO-2).
	 *
	 * @param int               $id     Store ID.
	 * @param string            $status New status.
	 * @param DateTimeImmutable $now    Current time.
	 */
	public function setStatus( int $id, string $status, DateTimeImmutable $now ): bool {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the module's own table.
		$updated = $this->db->query( self::prepared( $this->db, 'UPDATE %i SET status = %s, updated_at = %s, version = version + 1 WHERE id = %d AND status <> %s', $this->stores(), $status, EventPayloadSerializer::format( $now ), $id, $status ) );

		return 1 === $updated;
	}

	/**
	 * Clears a store's contact details (SEC-7).
	 *
	 * @param int               $id  Store ID.
	 * @param DateTimeImmutable $now Current time.
	 */
	public function eraseContact( int $id, DateTimeImmutable $now ): bool {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the module's own table.
		$updated = $this->db->query( self::prepared( $this->db, "UPDATE %i SET contact_email = '', contact_phone = '', show_email = 0, show_phone = 0, updated_at = %s, version = version + 1 WHERE id = %d AND ( contact_email <> '' OR contact_phone <> '' )", $this->stores(), EventPayloadSerializer::format( $now ), $id ) );

		return 1 === $updated;
	}

	/**
	 * One page of stores with their default locations, with one extra row to tell whether more follow.
	 *
	 * @param ListRequest $request Validated list request: sorted by name or id; visibility and status filters.
	 * @param string      $search  Name prefix, or '' for all.
	 * @return list<array{0: Store, 1: Location|null}>
	 */
	public function page( ListRequest $request, string $search ): array {
		$descending = 'desc' === $request->direction;
		$by_name    = 'name' === $request->sort;
		$visibility = $request->filters['visibility'] ?? '';
		$status     = $request->filters['status'] ?? '';
		$where      = "( %s = '' OR s.visibility = %s ) AND ( %s = '' OR s.status = %s ) AND ( %s = '' OR s.name LIKE %s )";
		$values     = array( $visibility, $visibility, $status, $status, $search, $this->db->esc_like( $search ) . '%' );
		if ( null !== $request->afterId ) {
			$compare = $descending ? '<' : '>';
			if ( $by_name ) {
				$where   .= " AND ( s.name {$compare} %s OR ( s.name = %s AND s.id {$compare} %d ) )";
				$values[] = (string) $request->afterValue;
				$values[] = (string) $request->afterValue;
				$values[] = $request->afterId;
			} else {
				$where   .= " AND s.id {$compare} %d";
				$values[] = $request->afterId;
			}
		}
		$direction = $descending ? 'DESC' : 'ASC';
		$order     = $by_name ? "s.name {$direction}, s.id {$direction}" : "s.id {$direction}";
		$query     = 'SELECT ' . self::COLUMNS . ' FROM %i s LEFT JOIN %i l ON ' . self::JOIN . " WHERE {$where} ORDER BY {$order} LIMIT %d";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- fixed SQL from allow-listed parts; prepared by the helper.
		$rows = $this->db->get_results( self::prepared( $this->db, $query, $this->stores(), $this->locations(), ...array_merge( $values, array( $request->perPage + 1 ) ) ), ARRAY_A );

		return array_map( array( self::class, 'row' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * One store matching a condition.
	 *
	 * @param literal-string $condition SQL condition on `s`, with one placeholder.
	 * @param int|string     $value     Its value.
	 * @return array{0: Store, 1: Location|null}|null
	 */
	private function one( string $condition, int|string $value ): ?array {
		$query = 'SELECT ' . self::COLUMNS . ' FROM %i s LEFT JOIN %i l ON ' . self::JOIN . ' WHERE ' . $condition;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- fixed SQL; prepared by the helper.
		$row = $this->db->get_row( self::prepared( $this->db, $query, $this->stores(), $this->locations(), $value ), ARRAY_A );

		return is_array( $row ) ? self::row( $row ) : null;
	}

	/**
	 * A store and its location from a joined row.
	 *
	 * @param array<string, mixed> $row Row.
	 * @return array{0: Store, 1: Location|null}
	 */
	private static function row( array $row ): array {
		$profile = array();
		foreach ( array_keys( StoreProfile::TEXT ) as $field ) {
			$profile[ $field ] = (string) ( $row[ $field ] ?? '' );
		}
		$store = new Store(
			(int) $row['id'],
			(int) $row['vendor_id'],
			(string) $row['name'],
			(string) $row['slug'],
			$profile,
			'1' === (string) $row['show_email'],
			'1' === (string) $row['show_phone'],
			null === $row['logo_id'] ? null : (int) $row['logo_id'],
			null === $row['banner_id'] ? null : (int) $row['banner_id'],
			(string) $row['visibility'],
			(string) $row['status'],
			(string) $row['created_at'],
			(string) $row['updated_at'],
			(int) $row['version'],
		);
		if ( null === $row['l_id'] ) {
			return array( $store, null );
		}
		$address = array();
		foreach ( array_keys( StoreProfile::ADDRESS ) as $field ) {
			$address[ $field ] = (string) $row[ 'l_' . $field ];
		}

		return array(
			$store,
			new Location(
				(int) $row['l_id'],
				$store->vendorId,
				(string) $row['l_type'],
				true,
				(string) $row['l_status'],
				$address,
				null === $row['l_latitude'] ? null : (float) $row['l_latitude'],
				null === $row['l_longitude'] ? null : (float) $row['l_longitude'],
				null === $row['l_geo_cell'] ? null : (string) $row['l_geo_cell'],
				(int) $row['l_version'],
			),
		);
	}

	/**
	 * The stores table.
	 */
	private function stores(): string {
		return $this->db->prefix . 'mercatus_stores';
	}

	/**
	 * The locations table.
	 */
	private function locations(): string {
		return $this->db->prefix . 'mercatus_fulfilment_locations';
	}
}
