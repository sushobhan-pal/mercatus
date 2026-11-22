<?php
/**
 * The media ownership table (DATA-2, CTL-14).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Catalog\Infrastructure;

use DateTimeImmutable;
use Mercatus\Core\Catalog\Domain\Media;
use Mercatus\Core\Infrastructure\Database\PreparesQueries;
use Mercatus\Core\Infrastructure\Outbox\EventPayloadSerializer;
use Mercatus\Core\Shared\Http\ListRequest;
use RuntimeException;
use wpdb;

/**
 * The media list reads only this table, newest first through the (vendor_id, created_at) index.
 */
final class MediaRepository {

	use PreparesQueries;

	/**
	 * Creates the repository.
	 *
	 * @param wpdb $db WordPress database.
	 */
	public function __construct( private wpdb $db ) {}

	/**
	 * Records an upload.
	 *
	 * @param Media             $media The upload.
	 * @param DateTimeImmutable $now   Current time.
	 * @throws RuntimeException When the database refuses it.
	 */
	public function insert( Media $media, DateTimeImmutable $now ): void {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the module's own table.
		$inserted = $this->db->query( self::prepared( $this->db, 'INSERT INTO %i (attachment_id, vendor_id, file, thumbnail, mime, bytes, width, height, created_at) VALUES (%d, %d, %s, %s, %s, %d, %d, %d, %s)', $this->table(), $media->id, $media->vendorId, $media->file, $media->thumbnail, $media->mime, $media->bytes, $media->width, $media->height, EventPayloadSerializer::format( $now ) ) );
		if ( 1 !== $inserted ) {
			throw new RuntimeException( 'The upload could not be recorded.' );
		}
	}

	/**
	 * An upload by its attachment.
	 *
	 * @param int $id Attachment ID.
	 */
	public function byId( int $id ): ?Media {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the module's own table.
		$row = $this->db->get_row( self::prepared( $this->db, 'SELECT * FROM %i WHERE attachment_id = %d', $this->table(), $id ), ARRAY_A );

		return is_array( $row ) ? Media::fromArray( $row ) : null;
	}

	/**
	 * The attachments among some that a vendor uploaded.
	 *
	 * @param int       $vendor_id Vendor.
	 * @param list<int> $ids       Attachment IDs.
	 * @return list<int>
	 */
	public function ownedAmong( int $vendor_id, array $ids ): array {
		if ( array() === $ids ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- integer IDs appended after preparing.
		$owned = $this->db->get_col( self::prepared( $this->db, 'SELECT attachment_id FROM %i WHERE vendor_id = %d AND attachment_id IN (', $this->table(), $vendor_id ) . self::idList( $ids ) . ')' );

		return array_map( 'intval', $owned );
	}

	/**
	 * One page of a vendor's uploads, newest first, with one extra row to tell whether more follow.
	 *
	 * @param int         $vendor_id Vendor.
	 * @param ListRequest $request   Validated list request, sorted by created_at, newest first.
	 * @return list<Media>
	 */
	public function page( int $vendor_id, ListRequest $request ): array {
		$descending = 'desc' === $request->direction;
		$where      = 'vendor_id = %d';
		$values     = array( $vendor_id );
		if ( null !== $request->afterId ) {
			$where   .= $descending ? ' AND ( created_at < %s OR ( created_at = %s AND attachment_id < %d ) )' : ' AND ( created_at > %s OR ( created_at = %s AND attachment_id > %d ) )';
			$values[] = (string) $request->afterValue;
			$values[] = (string) $request->afterValue;
			$values[] = $request->afterId;
		}
		$order = $descending ? 'created_at DESC, attachment_id DESC' : 'created_at ASC, attachment_id ASC';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- fixed SQL from allow-listed parts; prepared by the helper.
		$rows = $this->db->get_results( self::prepared( $this->db, "SELECT * FROM %i WHERE {$where} ORDER BY {$order} LIMIT %d", $this->table(), ...array_merge( $values, array( $request->perPage + 1 ) ) ), ARRAY_A );

		return array_map( array( Media::class, 'fromArray' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * The space a vendor's uploads take, in bytes.
	 *
	 * @param int $vendor_id Vendor.
	 */
	public function usedBytes( int $vendor_id ): int {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the module's own table.
		return (int) $this->db->get_var( self::prepared( $this->db, 'SELECT COALESCE(SUM(bytes), 0) FROM %i WHERE vendor_id = %d', $this->table(), $vendor_id ) );
	}

	/**
	 * Forgets an upload, and says whether this call did.
	 *
	 * @param int $id Attachment ID.
	 */
	public function delete( int $id ): bool {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the module's own table.
		return 1 === $this->db->query( self::prepared( $this->db, 'DELETE FROM %i WHERE attachment_id = %d', $this->table(), $id ) );
	}

	/**
	 * The table.
	 */
	private function table(): string {
		return $this->db->prefix . 'mercatus_media_ownership';
	}
}
