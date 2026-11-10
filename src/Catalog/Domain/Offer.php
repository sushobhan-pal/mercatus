<?php
/**
 * A seller's offer for a catalog product (DATA-2, CAT-1, CTL-22).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Catalog\Domain;

use DateTimeImmutable;
use DateTimeZone;
use Mercatus\Core\Shared\Money\Decimal;

/**
 * The source of truth for price. A simple product's prices are the offer's; a variable product's live per
 * variation (`OfferVariation`). The vendor publishes (`active`) or unpublishes (`paused`) it.
 */
final readonly class Offer {

	public const ACTIVE = 'active';

	public const PAUSED = 'paused';

	public const DELETED = 'deleted';

	public const STATUSES = array( self::ACTIVE, self::PAUSED, self::DELETED );

	/**
	 * Creates the offer.
	 *
	 * @param int    $id               Offer ID.
	 * @param int    $catalogProductId Catalog product.
	 * @param int    $vendorId         Seller; 0 for the marketplace itself.
	 * @param string $status           active, paused or deleted.
	 * @param Prices $prices           A simple product's prices.
	 * @param int    $version          Row version.
	 */
	public function __construct(
		public int $id,
		public int $catalogProductId,
		public int $vendorId,
		public string $status,
		public Prices $prices,
		public int $version,
	) {}

	/**
	 * The offer as a cacheable array.
	 *
	 * @return array<string, int|string|null>
	 */
	public function toArray(): array {
		return array(
			'id'                 => $this->id,
			'catalog_product_id' => $this->catalogProductId,
			'vendor_id'          => $this->vendorId,
			'status'             => $this->status,
			'version'            => $this->version,
		) + self::pricesToRow( $this->prices );
	}

	/**
	 * An offer from toArray()'s output, or from a database row.
	 *
	 * @param array<string, mixed> $row Row.
	 */
	public static function fromArray( array $row ): self {
		return new self( (int) $row['id'], (int) $row['catalog_product_id'], (int) $row['vendor_id'], (string) $row['status'], self::pricesFromRow( $row ), (int) $row['version'] );
	}

	/**
	 * Prices as database columns.
	 *
	 * @param Prices $prices Prices.
	 * @return array{base_price: string|null, sale_price: string|null, sale_starts_at: string|null, sale_ends_at: string|null}
	 */
	public static function pricesToRow( Prices $prices ): array {
		return array(
			'base_price'     => $prices->regular?->toDatabase(),
			'sale_price'     => $prices->sale?->toDatabase(),
			'sale_starts_at' => $prices->saleStartsAt?->format( 'Y-m-d H:i:s.v' ),
			'sale_ends_at'   => $prices->saleEndsAt?->format( 'Y-m-d H:i:s.v' ),
		);
	}

	/**
	 * Prices from database columns.
	 *
	 * @param array<string, mixed> $row Row.
	 */
	public static function pricesFromRow( array $row ): Prices {
		$utc = new DateTimeZone( 'UTC' );

		return new Prices(
			null === ( $row['base_price'] ?? null ) ? null : Decimal::of( (string) $row['base_price'] ),
			null === ( $row['sale_price'] ?? null ) ? null : Decimal::of( (string) $row['sale_price'] ),
			null === ( $row['sale_starts_at'] ?? null ) ? null : new DateTimeImmutable( (string) $row['sale_starts_at'], $utc ),
			null === ( $row['sale_ends_at'] ?? null ) ? null : new DateTimeImmutable( (string) $row['sale_ends_at'], $utc ),
		);
	}
}
