<?php
/**
 * The fulfilment locations table (DATA-2, CAT-1, STO-4).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Store\Infrastructure;

use DateTimeImmutable;
use Mercatus\Core\Infrastructure\Database\PreparesQueries;
use Mercatus\Core\Infrastructure\Outbox\EventPayloadSerializer;
use wpdb;

/**
 * Writes only; stores read their default location through StoreRepository's join.
 */
final class LocationRepository {

	use PreparesQueries;

	/**
	 * Creates the repository.
	 *
	 * @param wpdb $db WordPress database.
	 */
	public function __construct( private wpdb $db ) {}

	/**
	 * Creates a vendor's default location, with an empty address, and returns its ID.
	 *
	 * @param int               $vendor_id Vendor.
	 * @param DateTimeImmutable $now       Current time.
	 */
	public function insertDefault( int $vendor_id, DateTimeImmutable $now ): int {
		$time = EventPayloadSerializer::format( $now );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the module's own table.
		$this->db->query( self::prepared( $this->db, "INSERT INTO %i (vendor_id, type, is_default, status, created_at, updated_at) VALUES (%d, 'default', 1, 'active', %s, %s)", $this->table(), $vendor_id, $time, $time ) );

		return (int) $this->db->insert_id;
	}

	/**
	 * Changes a location's address and clears its coordinates, which belong to the old address (STO-14).
	 *
	 * @param int                   $id      Location ID.
	 * @param array<string, string> $address Address fields to change.
	 * @param DateTimeImmutable     $now     Current time.
	 */
	public function updateAddress( int $id, array $address, DateTimeImmutable $now ): bool {
		$sets   = array();
		$values = array();
		foreach ( array( 'address_1', 'address_2', 'city', 'state', 'postcode', 'country' ) as $field ) {
			if ( isset( $address[ $field ] ) ) {
				$sets[]   = $field . ' = %s';
				$values[] = $address[ $field ];
			}
		}
		if ( array() === $sets ) {
			return false;
		}
		$query = 'UPDATE %i SET ' . implode( ', ', $sets ) . ', latitude = NULL, longitude = NULL, geo_cell = NULL, updated_at = %s, version = version + 1 WHERE id = %d';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- columns from a fixed list; prepared by the helper.
		$updated = $this->db->query( self::prepared( $this->db, $query, $this->table(), ...array_merge( $values, array( EventPayloadSerializer::format( $now ), $id ) ) ) );

		return 1 === $updated;
	}

	/**
	 * Sets a location's coordinates and geohash cell, as a geocoder finds them (CAT-6, STO-14).
	 *
	 * @param int               $id        Location ID.
	 * @param float             $latitude  Latitude.
	 * @param float             $longitude Longitude.
	 * @param string            $geo_cell  Geohash cell (Geohash::CELL characters).
	 * @param DateTimeImmutable $now       Current time.
	 */
	public function setCoordinates( int $id, float $latitude, float $longitude, string $geo_cell, DateTimeImmutable $now ): bool {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the module's own table.
		$updated = $this->db->query( self::prepared( $this->db, 'UPDATE %i SET latitude = %f, longitude = %f, geo_cell = %s, updated_at = %s, version = version + 1 WHERE id = %d', $this->table(), $latitude, $longitude, $geo_cell, EventPayloadSerializer::format( $now ), $id ) );

		return 1 === $updated;
	}

	/**
	 * The locations table.
	 */
	private function table(): string {
		return $this->db->prefix . 'mercatus_fulfilment_locations';
	}
}
