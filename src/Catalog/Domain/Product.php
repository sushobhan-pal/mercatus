<?php
/**
 * A catalog product: one WooCommerce product and its governance state (DATA-2, CTL-1, CTL-5).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Catalog\Domain;

/**
 * The product's content lives in WooCommerce (CTL-2); this is what Mercatus decides about it. Who sells it is
 * the offer's business; `ownerVendorId` says whose content it is, `0` for the marketplace's own (CTL-23).
 */
final readonly class Product {

	public const DRAFT = 'draft';

	public const PENDING = 'pending';

	public const APPROVED = 'approved';

	public const REJECTED = 'rejected';

	public const TAKEN_DOWN = 'taken_down';

	public const TRASHED = 'trashed';

	public const DELETED = 'deleted';

	public const STATUSES = array( self::DRAFT, self::PENDING, self::APPROVED, self::REJECTED, self::TAKEN_DOWN, self::TRASHED, self::DELETED );

	public const SIMPLE = 'simple';

	public const VARIABLE = 'variable';

	public const TYPES = array( self::SIMPLE, self::VARIABLE );

	public const SCOPE_VENDOR = 'vendor';

	public const SCOPE_MARKETPLACE = 'marketplace';

	/**
	 * The seller ID of the marketplace itself (CAT-1, CTL-23).
	 */
	public const MARKETPLACE = 0;

	/**
	 * Creates the product.
	 *
	 * @param int      $id                Catalog product ID (internal, CTL-3).
	 * @param int      $wooProductId      The WooCommerce product, in its original language.
	 * @param string   $scope             vendor or marketplace.
	 * @param int      $ownerVendorId     Whose content it is; 0 for the marketplace's own.
	 * @param string   $type              simple or variable.
	 * @param string   $status            Governance state.
	 * @param string   $statusReason      The latest rejection or take-down reason.
	 * @param int|null $primaryCategoryId The first WooCommerce category chosen.
	 * @param string   $createdAt         Created, UTC.
	 * @param string   $updatedAt         Updated, UTC.
	 * @param int      $version           Version: any change to the product, its offer, prices or stock bumps it (CTL-7).
	 */
	public function __construct(
		public int $id,
		public int $wooProductId,
		public string $scope,
		public int $ownerVendorId,
		public string $type,
		public string $status,
		public string $statusReason,
		public ?int $primaryCategoryId,
		public string $createdAt,
		public string $updatedAt,
		public int $version,
	) {}

	/**
	 * Whether it's the marketplace's own (CTL-23).
	 */
	public function isMarketplaces(): bool {
		return self::SCOPE_MARKETPLACE === $this->scope;
	}

	/**
	 * The product as a cacheable array.
	 *
	 * @return array<string, int|string|null>
	 */
	public function toArray(): array {
		return array(
			'id'                  => $this->id,
			'woo_product_id'      => $this->wooProductId,
			'scope'               => $this->scope,
			'owner_vendor_id'     => $this->ownerVendorId,
			'product_type'        => $this->type,
			'status'              => $this->status,
			'status_reason'       => $this->statusReason,
			'primary_category_id' => $this->primaryCategoryId,
			'created_at'          => $this->createdAt,
			'updated_at'          => $this->updatedAt,
			'version'             => $this->version,
		);
	}

	/**
	 * A product from toArray()'s output, or from a database row.
	 *
	 * @param array<string, mixed> $row Row.
	 */
	public static function fromArray( array $row ): self {
		return new self(
			(int) $row['id'],
			(int) $row['woo_product_id'],
			(string) $row['scope'],
			(int) $row['owner_vendor_id'],
			(string) $row['product_type'],
			(string) $row['status'],
			(string) ( $row['status_reason'] ?? '' ),
			null === ( $row['primary_category_id'] ?? null ) ? null : (int) $row['primary_category_id'],
			(string) $row['created_at'],
			(string) $row['updated_at'],
			(int) $row['version'],
		);
	}
}
