<?php
/**
 * Stock balances and their journal (DATA-2, CTL-6).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Catalog\Infrastructure;

use DateTimeImmutable;
use Mercatus\Core\Catalog\Domain\Balance;
use Mercatus\Core\Infrastructure\Database\PreparesQueries;
use Mercatus\Core\Infrastructure\Outbox\EventPayloadSerializer;
use Mercatus\Core\Shared\Actor\ActorRef;
use Mercatus\Core\Shared\Money\Decimal;
use RuntimeException;
use wpdb;

/**
 * A balance never changes without a movement in the same transaction: callers write both inside one command.
 * Changes are optimistic on the balance's `version`.
 */
final class InventoryRepository {

	use PreparesQueries;

	/**
	 * Creates the repository.
	 *
	 * @param wpdb $db WordPress database.
	 */
	public function __construct( private wpdb $db ) {}

	/**
	 * An offer's balances, by variation (0 for a simple product).
	 *
	 * @param int $offer_id Offer ID.
	 * @return array<int, Balance>
	 */
	public function forOffer( int $offer_id ): array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the module's own table.
		$rows     = $this->db->get_results( self::prepared( $this->db, 'SELECT * FROM %i WHERE vendor_offer_id = %d ORDER BY variation_id, location_id', $this->balances(), $offer_id ), ARRAY_A );
		$balances = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$balance                             = Balance::fromArray( $row );
			$balances[ $balance->variationId ] ??= $balance;
		}

		return $balances;
	}

	/**
	 * Creates a balance and returns its ID.
	 *
	 * @param Balance           $balance New balance (its ID and version are ignored).
	 * @param DateTimeImmutable $now     Current time.
	 * @throws RuntimeException When the database refuses it.
	 */
	public function insert( Balance $balance, DateTimeImmutable $now ): int {
		$time = EventPayloadSerializer::format( $now );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the module's own table.
		$inserted = $this->db->query( self::prepared( $this->db, 'INSERT INTO %i (vendor_offer_id, variation_id, location_id, manage_stock, quantity, stock_status, backorders, created_at, updated_at) VALUES (%d, %d, %d, %d, %s, %s, %s, %s, %s)', $this->balances(), $balance->offerId, $balance->variationId, $balance->locationId, (int) $balance->manageStock, $balance->quantity->toDatabase(), $balance->stockStatus, $balance->backorders, $time, $time ) );
		if ( 1 !== $inserted ) {
			throw new RuntimeException( 'The balance could not be created.' );
		}

		return (int) $this->db->insert_id;
	}

	/**
	 * Changes a balance, if it's still at the expected version.
	 *
	 * @param Balance           $balance The balance's new values, with the version they were based on.
	 * @param DateTimeImmutable $now     Current time.
	 */
	public function update( Balance $balance, DateTimeImmutable $now ): bool {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the module's own table.
		$updated = $this->db->query( self::prepared( $this->db, 'UPDATE %i SET manage_stock = %d, quantity = %s, stock_status = %s, backorders = %s, updated_at = %s, version = version + 1 WHERE id = %d AND version = %d', $this->balances(), (int) $balance->manageStock, $balance->quantity->toDatabase(), $balance->stockStatus, $balance->backorders, EventPayloadSerializer::format( $now ), $balance->id, $balance->version ) );

		return 1 === $updated;
	}

	/**
	 * Records a movement in the journal.
	 *
	 * @param string            $uuid       Movement UUID.
	 * @param int               $balance_id Balance.
	 * @param Decimal           $delta      Change.
	 * @param Decimal           $after      Quantity after it.
	 * @param string            $reason     Why (Balance::REASONS).
	 * @param ActorRef          $actor      Who.
	 * @param DateTimeImmutable $now        Current time.
	 */
	public function move( string $uuid, int $balance_id, Decimal $delta, Decimal $after, string $reason, ActorRef $actor, DateTimeImmutable $now ): void {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the module's own table.
		$this->db->query( self::prepared( $this->db, 'INSERT INTO %i (movement_uuid, balance_id, delta, quantity_after, reason, actor_type, actor_id, created_at) VALUES (%s, %d, %s, %s, %s, %s, %d, %s)', $this->movementsTable(), $uuid, $balance_id, $delta->toDatabase(), $after->toDatabase(), $reason, $actor->type, $actor->id, EventPayloadSerializer::format( $now ) ) );
	}

	/**
	 * A balance's movements, newest first.
	 *
	 * @param int $balance_id Balance.
	 * @param int $limit      Most movements.
	 * @return list<array<string, string>>
	 */
	public function movements( int $balance_id, int $limit = 50 ): array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the module's own table.
		$rows = $this->db->get_results( self::prepared( $this->db, 'SELECT movement_uuid, delta, quantity_after, reason, actor_type, actor_id, created_at FROM %i WHERE balance_id = %d ORDER BY created_at DESC, id DESC LIMIT %d', $this->movementsTable(), $balance_id, $limit ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * The balances table.
	 */
	private function balances(): string {
		return $this->db->prefix . 'mercatus_inventory_balances';
	}

	/**
	 * The movements table.
	 */
	private function movementsTable(): string {
		return $this->db->prefix . 'mercatus_inventory_movements';
	}
}
