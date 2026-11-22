<?php
/**
 * Sellable stock for one offer, variation and location (DATA-2, CTL-6).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Catalog\Domain;

use Mercatus\Core\Shared\Money\Decimal;

/**
 * The source of truth for stock. "Manage stock", stock status and backorders work as they do in WooCommerce:
 * with stock managed, the status follows the quantity; without, the vendor sets it.
 */
final readonly class Balance {

	public const IN_STOCK = 'instock';

	public const OUT_OF_STOCK = 'outofstock';

	public const ON_BACKORDER = 'onbackorder';

	public const STOCK_STATUSES = array( self::IN_STOCK, self::OUT_OF_STOCK, self::ON_BACKORDER );

	public const BACKORDERS = array( 'no', 'notify', 'yes' );

	/**
	 * Why stock changed, as each movement records it.
	 */
	public const REASONS = array( 'initial', 'set', 'adjust', 'order', 'outside', 'reconcile' );

	/**
	 * Creates the balance.
	 *
	 * @param int     $id          Balance ID; 0 before it's stored.
	 * @param int     $offerId     Offer.
	 * @param int     $variationId Variation; 0 for a simple product.
	 * @param int     $locationId  Fulfilment location.
	 * @param bool    $manageStock Whether stock is counted.
	 * @param Decimal $quantity    Quantity; whole numbers in Free.
	 * @param string  $stockStatus instock, outofstock or onbackorder.
	 * @param string  $backorders  no, notify or yes.
	 * @param int     $version     Row version.
	 */
	public function __construct(
		public int $id,
		public int $offerId,
		public int $variationId,
		public int $locationId,
		public bool $manageStock,
		public Decimal $quantity,
		public string $stockStatus,
		public string $backorders,
		public int $version,
	) {}

	/**
	 * The stock status WooCommerce would give: with stock managed, in stock while the quantity is above zero,
	 * then on backorder if backorders are allowed, else out of stock; without, the vendor's choice.
	 *
	 * @param bool    $manage_stock Whether stock is counted.
	 * @param Decimal $quantity     Quantity.
	 * @param string  $backorders   no, notify or yes.
	 * @param string  $chosen       The status chosen when stock isn't counted.
	 */
	public static function status( bool $manage_stock, Decimal $quantity, string $backorders, string $chosen ): string {
		if ( ! $manage_stock ) {
			return $chosen;
		}
		if ( $quantity->compare( Decimal::zero() ) > 0 ) {
			return self::IN_STOCK;
		}

		return 'no' === $backorders ? self::OUT_OF_STOCK : self::ON_BACKORDER;
	}

	/**
	 * Whether buyers may order it.
	 */
	public function sellable(): bool {
		return self::OUT_OF_STOCK !== $this->stockStatus;
	}

	/**
	 * The balance as a cacheable array.
	 *
	 * @return array<string, int|string|bool>
	 */
	public function toArray(): array {
		return array(
			'id'              => $this->id,
			'vendor_offer_id' => $this->offerId,
			'variation_id'    => $this->variationId,
			'location_id'     => $this->locationId,
			'manage_stock'    => $this->manageStock,
			'quantity'        => $this->quantity->toDatabase(),
			'stock_status'    => $this->stockStatus,
			'backorders'      => $this->backorders,
			'version'         => $this->version,
		);
	}

	/**
	 * A balance from toArray()'s output, or from a database row.
	 *
	 * @param array<string, mixed> $row Row.
	 */
	public static function fromArray( array $row ): self {
		return new self(
			(int) $row['id'],
			(int) $row['vendor_offer_id'],
			(int) $row['variation_id'],
			(int) $row['location_id'],
			true === $row['manage_stock'] || '1' === (string) $row['manage_stock'],
			Decimal::of( (string) $row['quantity'] ),
			(string) $row['stock_status'],
			(string) $row['backorders'],
			(int) $row['version'],
		);
	}
}
