<?php
/**
 * Offers, their variations' prices and their locations (DATA-2, CAT-1, CTL-22).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Catalog\Infrastructure;

use DateTimeImmutable;
use Mercatus\Core\Catalog\Domain\Offer;
use Mercatus\Core\Catalog\Domain\OfferVariation;
use Mercatus\Core\Catalog\Domain\Prices;
use Mercatus\Core\Infrastructure\Database\PreparesQueries;
use Mercatus\Core\Infrastructure\Outbox\EventPayloadSerializer;
use RuntimeException;
use wpdb;

/**
 * Prices are stored as `DECIMAL(20,6)` strings and dates as UTC; an absent price is NULL (DATA-1).
 */
final class OfferRepository {

	use PreparesQueries;

	private const PRICE_SQL = 'base_price = NULLIF(%s, \'\'), sale_price = NULLIF(%s, \'\'), sale_starts_at = NULLIF(%s, \'\'), sale_ends_at = NULLIF(%s, \'\')';

	/**
	 * Creates the repository.
	 *
	 * @param wpdb $db WordPress database.
	 */
	public function __construct( private wpdb $db ) {}

	/**
	 * Creates an offer and returns its ID.
	 *
	 * @param int               $product_id Catalog product.
	 * @param int               $vendor_id  Seller; 0 for the marketplace.
	 * @param string            $status     active or paused.
	 * @param Prices            $prices     A simple product's prices.
	 * @param DateTimeImmutable $now        Current time.
	 * @throws RuntimeException When the database refuses it.
	 */
	public function insert( int $product_id, int $vendor_id, string $status, Prices $prices, DateTimeImmutable $now ): int {
		$time = EventPayloadSerializer::format( $now );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the module's own table.
		$inserted = $this->db->query( self::prepared( $this->db, 'INSERT INTO %i SET catalog_product_id = %d, vendor_id = %d, status = %s, ' . self::PRICE_SQL . ', created_at = %s, updated_at = %s', $this->offers(), $product_id, $vendor_id, $status, ...array_merge( self::priceValues( $prices ), array( $time, $time ) ) ) );
		if ( 1 !== $inserted ) {
			throw new RuntimeException( 'The offer could not be created.' );
		}

		return (int) $this->db->insert_id;
	}

	/**
	 * A product's offers (one in Free, CAT-1).
	 *
	 * @param int $product_id Catalog product.
	 * @return list<Offer>
	 */
	public function forProduct( int $product_id ): array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the module's own table.
		$rows = $this->db->get_results( self::prepared( $this->db, "SELECT * FROM %i WHERE catalog_product_id = %d AND status <> 'deleted' ORDER BY id", $this->offers(), $product_id ), ARRAY_A );

		return array_map( array( Offer::class, 'fromArray' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * An offer by ID.
	 *
	 * @param int $id Offer ID.
	 */
	public function byId( int $id ): ?Offer {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the module's own table.
		$row = $this->db->get_row( self::prepared( $this->db, 'SELECT * FROM %i WHERE id = %d', $this->offers(), $id ), ARRAY_A );

		return is_array( $row ) ? Offer::fromArray( $row ) : null;
	}

	/**
	 * Changes an offer's state and prices.
	 *
	 * @param int               $id     Offer ID.
	 * @param string            $status active, paused or deleted.
	 * @param Prices            $prices Prices.
	 * @param DateTimeImmutable $now    Current time.
	 */
	public function update( int $id, string $status, Prices $prices, DateTimeImmutable $now ): void {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the module's own table.
		$this->db->query( self::prepared( $this->db, 'UPDATE %i SET status = %s, ' . self::PRICE_SQL . ', updated_at = %s, version = version + 1 WHERE id = %d', $this->offers(), $status, ...array_merge( self::priceValues( $prices ), array( EventPayloadSerializer::format( $now ), $id ) ) ) );
	}

	/**
	 * An offer's variations, deleted ones left out.
	 *
	 * @param int $offer_id Offer ID.
	 * @return list<OfferVariation>
	 */
	public function variations( int $offer_id ): array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the module's own table.
		$rows = $this->db->get_results( self::prepared( $this->db, "SELECT * FROM %i WHERE vendor_offer_id = %d AND status <> 'deleted' ORDER BY variation_id", $this->variationsTable(), $offer_id ), ARRAY_A );

		return array_map( array( OfferVariation::class, 'fromArray' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Records a variation's terms, creating its row the first time.
	 *
	 * @param int               $offer_id     Offer ID.
	 * @param int               $variation_id WooCommerce variation.
	 * @param string            $status       enabled, disabled or deleted.
	 * @param Prices            $prices       Prices.
	 * @param DateTimeImmutable $now          Current time.
	 */
	public function saveVariation( int $offer_id, int $variation_id, string $status, Prices $prices, DateTimeImmutable $now ): void {
		$time = EventPayloadSerializer::format( $now );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the module's own table; prepared by the helper.
		$this->db->query( self::prepared( $this->db, 'INSERT INTO %i SET vendor_offer_id = %d, variation_id = %d, status = %s, ' . self::PRICE_SQL . ', created_at = %s, updated_at = %s ON DUPLICATE KEY UPDATE status = VALUES(status), base_price = VALUES(base_price), sale_price = VALUES(sale_price), sale_starts_at = VALUES(sale_starts_at), sale_ends_at = VALUES(sale_ends_at), updated_at = VALUES(updated_at), version = version + 1', $this->variationsTable(), $offer_id, $variation_id, $status, ...array_merge( self::priceValues( $prices ), array( $time, $time ) ) ) );
	}

	/**
	 * Places an offer at a location, once.
	 *
	 * @param int               $offer_id    Offer ID.
	 * @param int               $location_id Fulfilment location.
	 * @param DateTimeImmutable $now         Current time.
	 */
	public function placeAt( int $offer_id, int $location_id, DateTimeImmutable $now ): void {
		$time = EventPayloadSerializer::format( $now );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the module's own table.
		$this->db->query( self::prepared( $this->db, "INSERT IGNORE INTO %i (vendor_offer_id, location_id, status, created_at, updated_at) VALUES (%d, %d, 'active', %s, %s)", $this->locationsTable(), $offer_id, $location_id, $time, $time ) );
	}

	/**
	 * The locations an offer is active at (one in Free, CAT-1).
	 *
	 * @param int $offer_id Offer ID.
	 * @return list<int>
	 */
	public function locations( int $offer_id ): array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the module's own table.
		$ids = $this->db->get_col( self::prepared( $this->db, "SELECT location_id FROM %i WHERE vendor_offer_id = %d AND status = 'active' ORDER BY id", $this->locationsTable(), $offer_id ) );

		return array_map( 'intval', $ids );
	}

	/**
	 * Prices as query values, '' standing for NULL.
	 *
	 * @param Prices $prices Prices.
	 * @return list<string>
	 */
	private static function priceValues( Prices $prices ): array {
		return array_map( static fn( ?string $value ): string => $value ?? '', array_values( Offer::pricesToRow( $prices ) ) );
	}

	/**
	 * The offers table.
	 */
	private function offers(): string {
		return $this->db->prefix . 'mercatus_vendor_offers';
	}

	/**
	 * The offer variations table.
	 */
	private function variationsTable(): string {
		return $this->db->prefix . 'mercatus_offer_variations';
	}

	/**
	 * The offer locations table.
	 */
	private function locationsTable(): string {
		return $this->db->prefix . 'mercatus_offer_locations';
	}
}
