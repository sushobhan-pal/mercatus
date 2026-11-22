<?php
/**
 * A variable product's prices for one variation (DATA-2 `offer_variations`, CTL-22).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Catalog\Domain;

/**
 * One row per variation of an offer. A disabled variation keeps its prices but isn't sold.
 */
final readonly class OfferVariation {

	public const ENABLED = 'enabled';

	public const DISABLED = 'disabled';

	public const DELETED = 'deleted';

	/**
	 * Creates the variation's terms.
	 *
	 * @param int    $id          Row ID.
	 * @param int    $offerId     Offer.
	 * @param int    $variationId The WooCommerce variation.
	 * @param string $status      enabled, disabled or deleted.
	 * @param Prices $prices      Prices.
	 * @param int    $version     Row version.
	 */
	public function __construct(
		public int $id,
		public int $offerId,
		public int $variationId,
		public string $status,
		public Prices $prices,
		public int $version,
	) {}

	/**
	 * Whether buyers may choose it.
	 */
	public function isEnabled(): bool {
		return self::ENABLED === $this->status;
	}

	/**
	 * The terms as a cacheable array.
	 *
	 * @return array<string, int|string|null>
	 */
	public function toArray(): array {
		return array(
			'id'              => $this->id,
			'vendor_offer_id' => $this->offerId,
			'variation_id'    => $this->variationId,
			'status'          => $this->status,
			'version'         => $this->version,
		) + Offer::pricesToRow( $this->prices );
	}

	/**
	 * Terms from toArray()'s output, or from a database row.
	 *
	 * @param array<string, mixed> $row Row.
	 */
	public static function fromArray( array $row ): self {
		return new self( (int) $row['id'], (int) $row['vendor_offer_id'], (int) $row['variation_id'], (string) $row['status'], Offer::pricesFromRow( $row ), (int) $row['version'] );
	}
}
