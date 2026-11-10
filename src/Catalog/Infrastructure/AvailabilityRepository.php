<?php
/**
 * The availability projection (DATA-2 `offer_availability`, CAT-5, CTL-9).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Catalog\Infrastructure;

use Mercatus\Core\Infrastructure\Database\PreparesQueries;
use Mercatus\Core\Shared\Http\ListRequest;
use wpdb;

/**
 * Every Mercatus list reads these rows, never `WP_Query` or `wp_postmeta` (G-DB-1). A product's rows are keyed
 * `{offer}:{variation}:{location}`; the row for the product as a whole has variation 0, and lists read only
 * those. Rows are read and written in their stored form, strings and nulls, for the projection runner.
 */
final class AvailabilityRepository {

	use PreparesQueries;

	/**
	 * The projected columns, in write order.
	 */
	public const COLUMNS = array( 'vendor_offer_id', 'variation_id', 'location_id', 'catalog_product_id', 'vendor_id', 'status', 'is_purchasable', 'effective_price', 'min_price', 'max_price', 'stock_status', 'stock_quantity', 'name', 'sku', 'card', 'geo_cell', 'latitude', 'longitude', 'updated_at', 'version' );

	/**
	 * The projected columns, for SELECT.
	 */
	private const SELECT = 'vendor_offer_id, variation_id, location_id, catalog_product_id, vendor_id, status, is_purchasable, effective_price, min_price, max_price, stock_status, stock_quantity, name, sku, card, geo_cell, latitude, longitude, updated_at, version';

	/**
	 * Creates the repository.
	 *
	 * @param wpdb $db WordPress database.
	 */
	public function __construct( private wpdb $db ) {}

	/**
	 * A row's key within its product.
	 *
	 * @param int|string $offer_id     Offer.
	 * @param int|string $variation_id Variation, 0 for the product as a whole.
	 * @param int|string $location_id  Location.
	 */
	public static function key( int|string $offer_id, int|string $variation_id, int|string $location_id ): string {
		return $offer_id . ':' . $variation_id . ':' . $location_id;
	}

	/**
	 * A product's rows, by key.
	 *
	 * @param int $product_id Catalog product.
	 * @return array<string, array<string, string|null>>
	 */
	public function rows( int $product_id ): array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- the module's own table; fixed columns; prepared by the helper.
		$rows  = $this->db->get_results( self::prepared( $this->db, 'SELECT ' . self::SELECT . ' FROM %i WHERE catalog_product_id = %d', $this->table(), $product_id ), ARRAY_A );
		$keyed = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$row = array_map( static fn( mixed $value ): ?string => null === $value ? null : (string) $value, $row );
			$keyed[ self::key( (string) $row['vendor_offer_id'], (string) $row['variation_id'], (string) $row['location_id'] ) ] = $row;
		}

		return $keyed;
	}

	/**
	 * Writes a product's changed rows and removes the ones it no longer has.
	 *
	 * @param int                                       $product_id Catalog product.
	 * @param array<string, array<string, string|null>> $upserts    Rows by key.
	 * @param list<string>                              $deletes    Keys of rows to remove.
	 */
	public function write( int $product_id, array $upserts, array $deletes ): void {
		if ( array() !== $deletes ) {
			$keys = implode( ', ', array_map( fn( string $key ): string => self::prepared( $this->db, '%s', $key ), $deletes ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- each key is prepared above.
			$this->db->query( self::prepared( $this->db, "DELETE FROM %i WHERE catalog_product_id = %d AND CONCAT(vendor_offer_id, ':', variation_id, ':', location_id) IN (", $this->table(), $product_id ) . $keys . ')' );
		}
		if ( array() === $upserts ) {
			return;
		}
		$values = array();
		foreach ( $upserts as $row ) {
			$cells = array();
			foreach ( self::COLUMNS as $column ) {
				$value   = $row[ $column ] ?? null;
				$cells[] = null === $value ? 'NULL' : self::prepared( $this->db, '%s', $value );
			}
			$values[] = '(' . implode( ', ', $cells ) . ')';
		}
		$updates = implode( ', ', array_map( static fn( string $column ): string => "{$column} = VALUES({$column})", array_diff( self::COLUMNS, array( 'vendor_offer_id', 'variation_id', 'location_id' ) ) ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- every value is prepared above; columns are fixed.
		$this->db->query( 'INSERT INTO ' . $this->table() . ' (' . implode( ', ', self::COLUMNS ) . ') VALUES ' . implode( ', ', $values ) . " ON DUPLICATE KEY UPDATE {$updates}" );
	}

	/**
	 * One page of a vendor's products (their rows for the product as a whole), newest first, with one extra
	 * row to tell whether more follow. Uses the (vendor_id, variation_id[, status], catalog_product_id) indexes.
	 *
	 * @param int         $vendor_id Vendor.
	 * @param ListRequest $request   Validated list request, sorted by id (the catalog product's).
	 * @param string      $status    The state the vendor sees, or '' for all.
	 * @param string      $search    Name prefix or exact SKU, or '' for all.
	 * @return list<array<string, string|null>>
	 */
	public function vendorPage( int $vendor_id, ListRequest $request, string $status, string $search ): array {
		$descending = 'desc' === $request->direction;
		$where      = "vendor_id = %d AND variation_id = 0 AND ( %s = '' OR status = %s ) AND ( %s = '' OR name LIKE %s OR sku = %s )";
		$values     = array( $vendor_id, $status, $status, $search, $this->db->esc_like( $search ) . '%', $search );
		if ( null !== $request->afterId ) {
			$where   .= $descending ? ' AND catalog_product_id < %d' : ' AND catalog_product_id > %d';
			$values[] = $request->afterId;
		}
		$order = $descending ? 'DESC' : 'ASC';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- fixed SQL from allow-listed parts; prepared by the helper.
		$rows = $this->db->get_results( self::prepared( $this->db, 'SELECT ' . self::SELECT . " FROM %i WHERE {$where} ORDER BY catalog_product_id {$order} LIMIT %d", $this->table(), ...array_merge( $values, array( $request->perPage + 1 ) ) ), ARRAY_A );

		return array_map( static fn( array $row ): array => array_map( static fn( mixed $value ): ?string => null === $value ? null : (string) $value, $row ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Products' rows for the product as a whole, by catalog product ID.
	 *
	 * @param list<int> $product_ids Catalog products.
	 * @return array<int, array<string, string|null>>
	 */
	public function productRows( array $product_ids ): array {
		if ( array() === $product_ids ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer IDs; prepared by the helper.
		$rows  = $this->db->get_results( self::prepared( $this->db, 'SELECT ' . self::SELECT . ' FROM %i WHERE variation_id = 0 AND catalog_product_id IN (', $this->table() ) . self::idList( $product_ids ) . ')', ARRAY_A );
		$keyed = array();
		foreach ( $rows as $row ) {
			$keyed[ (int) $row['catalog_product_id'] ] = array_map( static fn( mixed $value ): ?string => null === $value ? null : (string) $value, $row );
		}

		return $keyed;
	}

	/**
	 * The catalog products of a seller that use a SKU, on the product or a variation (CTL-26).
	 *
	 * @param int    $vendor_id Seller; 0 for the marketplace.
	 * @param string $sku       SKU.
	 * @return list<int>
	 */
	public function productsWithSku( int $vendor_id, string $sku ): array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the module's own table.
		$ids = $this->db->get_col( self::prepared( $this->db, 'SELECT DISTINCT catalog_product_id FROM %i WHERE vendor_id = %d AND sku = %s', $this->table(), $vendor_id, $sku ) );

		return array_map( 'intval', $ids );
	}

	/**
	 * The sellers that use a SKU, with one of their products' WooCommerce IDs each (CTL-26).
	 *
	 * @param string $sku SKU.
	 * @return array<int, int> WooCommerce product or variation ID by seller.
	 */
	public function sellersWithSku( string $sku ): array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the module's own table.
		$rows    = $this->db->get_results( self::prepared( $this->db, 'SELECT vendor_id, variation_id, card FROM %i WHERE sku = %s', $this->table(), $sku ), ARRAY_A );
		$sellers = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$card = json_decode( (string) $row['card'], true );
			$id   = (int) $row['variation_id'] > 0 ? (int) $row['variation_id'] : ( is_array( $card ) ? (int) ( $card['id'] ?? 0 ) : 0 );
			if ( $id > 0 ) {
				$sellers[ (int) $row['vendor_id'] ] ??= $id;
			}
		}

		return $sellers;
	}

	/**
	 * The table.
	 */
	private function table(): string {
		return $this->db->prefix . 'mercatus_offer_availability';
	}
}
