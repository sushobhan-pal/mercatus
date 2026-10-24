<?php
/**
 * The vendors a marketplace manager may act on (IDN-15).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Contracts\Identity;

/**
 * All vendors in Free; Pro narrows it to vendor groups or membership plans, resolved to vendor IDs.
 */
final readonly class VendorScope {

	/**
	 * Creates the scope.
	 *
	 * @param list<int>|null $vendorIds Vendors in scope, ascending; null for all.
	 */
	private function __construct( public ?array $vendorIds ) {}

	/**
	 * Every vendor.
	 */
	public static function all(): self {
		return new self( null );
	}

	/**
	 * Only some vendors.
	 *
	 * @param list<int> $vendor_ids Vendor IDs.
	 */
	public static function only( array $vendor_ids ): self {
		$ids = array_values( array_unique( $vendor_ids ) );
		sort( $ids );

		return new self( $ids );
	}

	/**
	 * Whether a vendor is in scope.
	 *
	 * @param int $vendor_id Vendor ID.
	 */
	public function includes( int $vendor_id ): bool {
		return null === $this->vendorIds || in_array( $vendor_id, $this->vendorIds, true );
	}

	/**
	 * The vendors in both scopes.
	 *
	 * @param self $other Another scope.
	 */
	public function intersect( self $other ): self {
		if ( null === $this->vendorIds ) {
			return $other;
		}
		if ( null === $other->vendorIds ) {
			return $this;
		}

		return self::only( array_values( array_intersect( $this->vendorIds, $other->vendorIds ) ) );
	}
}
