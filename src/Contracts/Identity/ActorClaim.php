<?php
/**
 * What one module says a user is to it (IDN-3).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Contracts\Identity;

/**
 * A claim source returns these; the resolver combines every source's claims into one identity.
 */
final readonly class ActorClaim {

	/**
	 * Creates the claim.
	 *
	 * @param ActorType|null    $type             Type claimed; null for a claim that only narrows a manager's scope.
	 * @param int|null          $vendorId         The vendor the user owns or staffs.
	 * @param list<string>|null $storePermissions Staff's permissions inside the store; null for all of them.
	 * @param list<string>      $permissions      Named permissions across the marketplace (managers).
	 * @param VendorScope|null  $managerScope     Vendors a manager may act on.
	 */
	private function __construct(
		public ?ActorType $type,
		public ?int $vendorId = null,
		public ?array $storePermissions = null,
		public array $permissions = array(),
		public ?VendorScope $managerScope = null,
	) {}

	/**
	 * Any signed-in user.
	 */
	public static function customer(): self {
		return new self( ActorType::Customer );
	}

	/**
	 * A marketplace administrator.
	 */
	public static function administrator(): self {
		return new self( ActorType::Administrator );
	}

	/**
	 * An appointed marketplace manager, in scope for every vendor until another claim narrows it.
	 *
	 * @param list<string> $permissions The managers' shared permissions.
	 */
	public static function manager( array $permissions ): self {
		return new self( ActorType::MarketplaceManager, permissions: $permissions, managerScope: VendorScope::all() );
	}

	/**
	 * The owner of a vendor's store.
	 *
	 * @param int $vendor_id Vendor ID.
	 */
	public static function vendorOwner( int $vendor_id ): self {
		return new self( ActorType::VendorOwner, $vendor_id );
	}

	/**
	 * Staff of a vendor's store, with the permissions their owner granted (Pro).
	 *
	 * @param int          $vendor_id         Vendor ID.
	 * @param list<string> $store_permissions Permissions inside the store.
	 */
	public static function vendorStaff( int $vendor_id, array $store_permissions ): self {
		return new self( ActorType::VendorStaff, $vendor_id, $store_permissions );
	}

	/**
	 * A delivery agent (Pro).
	 */
	public static function deliveryAgent(): self {
		return new self( ActorType::DeliveryAgent );
	}

	/**
	 * An affiliate (Pro).
	 */
	public static function affiliate(): self {
		return new self( ActorType::Affiliate );
	}

	/**
	 * Narrows the scope of a user who is a manager; it doesn't make anyone a manager. Narrowing claims
	 * intersect (IDN-15).
	 *
	 * @param VendorScope $scope Vendors in scope.
	 */
	public static function managerScope( VendorScope $scope ): self {
		return new self( null, managerScope: $scope );
	}
}
