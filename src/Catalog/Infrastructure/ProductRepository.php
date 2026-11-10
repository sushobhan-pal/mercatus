<?php
/**
 * The catalog products table (DATA-2, CTL-5, CTL-7).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Catalog\Infrastructure;

use DateTimeImmutable;
use Mercatus\Core\Catalog\Domain\Product;
use Mercatus\Core\Infrastructure\Database\PreparesQueries;
use Mercatus\Core\Infrastructure\Outbox\EventPayloadSerializer;
use Mercatus\Core\Shared\Http\ListRequest;
use RuntimeException;
use wpdb;

/**
 * Changes are optimistic on `version`, which every change to the product, its offer, prices or stock bumps
 * (CTL-7, PLAT-9).
 */
final class ProductRepository {

	use PreparesQueries;

	private const COLUMNS = 'id, woo_product_id, scope, owner_vendor_id, product_type, status, status_reason, primary_category_id, created_at, updated_at, version';

	/**
	 * Creates the repository.
	 *
	 * @param wpdb $db WordPress database.
	 */
	public function __construct( private wpdb $db ) {}

	/**
	 * Creates a catalog product and returns its ID.
	 *
	 * @param int               $woo_product_id WooCommerce product.
	 * @param string            $scope          vendor or marketplace.
	 * @param int               $owner          Whose content it is.
	 * @param string            $type           simple or variable.
	 * @param string            $status         First state.
	 * @param int|null          $category_id    Primary category.
	 * @param DateTimeImmutable $now            Current time.
	 * @throws RuntimeException When the database refuses it.
	 */
	public function insert( int $woo_product_id, string $scope, int $owner, string $type, string $status, ?int $category_id, DateTimeImmutable $now ): int {
		$time = EventPayloadSerializer::format( $now );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the module's own table.
		$inserted = $this->db->query( self::prepared( $this->db, 'INSERT INTO %i (woo_product_id, scope, owner_vendor_id, product_type, status, primary_category_id, created_at, updated_at) VALUES (%d, %s, %d, %s, %s, NULLIF(%d, 0), %s, %s)', $this->table(), $woo_product_id, $scope, $owner, $type, $status, $category_id ?? 0, $time, $time ) );
		if ( 1 !== $inserted ) {
			throw new RuntimeException( 'The catalog product could not be created.' );
		}

		return (int) $this->db->insert_id;
	}

	/**
	 * A product by its catalog ID.
	 *
	 * @param int $id Catalog product ID.
	 */
	public function byId( int $id ): ?Product {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the module's own table.
		$row = $this->db->get_row( self::prepared( $this->db, 'SELECT ' . self::COLUMNS . ' FROM %i WHERE id = %d', $this->table(), $id ), ARRAY_A );

		return is_array( $row ) ? Product::fromArray( $row ) : null;
	}

	/**
	 * A product by its WooCommerce product.
	 *
	 * @param int $woo_product_id WooCommerce product ID.
	 */
	public function byWooId( int $woo_product_id ): ?Product {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the module's own table.
		$row = $this->db->get_row( self::prepared( $this->db, 'SELECT ' . self::COLUMNS . ' FROM %i WHERE woo_product_id = %d', $this->table(), $woo_product_id ), ARRAY_A );

		return is_array( $row ) ? Product::fromArray( $row ) : null;
	}

	/**
	 * Moves a product to a state, with a reason or '' to clear it, if it's still at the expected version.
	 *
	 * @param int               $id       Catalog product ID.
	 * @param int               $expected Version the change was based on.
	 * @param string            $status   New state.
	 * @param string            $reason   Reason, or ''.
	 * @param DateTimeImmutable $now      Current time.
	 */
	public function setStatus( int $id, int $expected, string $status, string $reason, DateTimeImmutable $now ): bool {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the module's own table.
		$updated = $this->db->query( self::prepared( $this->db, 'UPDATE %i SET status = %s, status_reason = %s, updated_at = %s, version = version + 1 WHERE id = %d AND version = %d', $this->table(), $status, $reason, EventPayloadSerializer::format( $now ), $id, $expected ) );

		return 1 === $updated;
	}

	/**
	 * Records a change to the product's content, offer, prices or stock (CTL-7), with its primary category,
	 * if it's still at the expected version.
	 *
	 * @param int               $id          Catalog product ID.
	 * @param int               $expected    Version the change was based on.
	 * @param int|null          $category_id Primary category.
	 * @param DateTimeImmutable $now         Current time.
	 */
	public function touch( int $id, int $expected, ?int $category_id, DateTimeImmutable $now ): bool {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the module's own table.
		$updated = $this->db->query( self::prepared( $this->db, 'UPDATE %i SET primary_category_id = NULLIF(%d, 0), updated_at = %s, version = version + 1 WHERE id = %d AND version = %d', $this->table(), $category_id ?? 0, EventPayloadSerializer::format( $now ), $id, $expected ) );

		return 1 === $updated;
	}

	/**
	 * One page of vendors' products for moderators, newest first, with one extra row to tell whether more
	 * follow. Uses the (scope, status, id) index.
	 *
	 * @param ListRequest $request   Validated list request, sorted by id.
	 * @param string      $status    State, or '' for all.
	 * @param int         $vendor_id Vendor, or 0 for all.
	 * @return list<Product>
	 */
	public function moderationPage( ListRequest $request, string $status, int $vendor_id ): array {
		$descending = 'desc' === $request->direction;
		$where      = "scope = 'vendor' AND ( %s = '' OR status = %s ) AND ( %d = 0 OR owner_vendor_id = %d ) AND status <> 'deleted'";
		$values     = array( $status, $status, $vendor_id, $vendor_id );
		if ( null !== $request->afterId ) {
			$where   .= $descending ? ' AND id < %d' : ' AND id > %d';
			$values[] = $request->afterId;
		}
		$order = $descending ? 'DESC' : 'ASC';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- fixed SQL from allow-listed parts; prepared by the helper.
		$rows = $this->db->get_results( self::prepared( $this->db, 'SELECT ' . self::COLUMNS . " FROM %i WHERE {$where} ORDER BY id {$order} LIMIT %d", $this->table(), ...array_merge( $values, array( $request->perPage + 1 ) ) ), ARRAY_A );

		return array_map( array( Product::class, 'fromArray' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * The table.
	 */
	private function table(): string {
		return $this->db->prefix . 'mercatus_catalog_products';
	}
}
