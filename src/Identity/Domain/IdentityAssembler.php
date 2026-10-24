<?php
/**
 * Combines claims into one identity (IDN-1, IDN-3, IDN-15).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Identity\Domain;

use Mercatus\Core\Contracts\Identity\ActorClaim;
use Mercatus\Core\Contracts\Identity\ActorIdentity;
use Mercatus\Core\Contracts\Identity\ActorType;
use Mercatus\Core\Contracts\Identity\VendorScope;

/**
 * The highest type wins. A user belongs to at most one store: conflicting store claims grant none, and the
 * owner or staff type then doesn't apply (fail closed). A manager starts in scope for every vendor and each
 * narrowing claim intersects.
 */
final class IdentityAssembler {

	/**
	 * The identity of a signed-in user.
	 *
	 * @param int              $user_id User ID.
	 * @param list<ActorClaim> $claims  Every source's claims.
	 */
	public static function assemble( int $user_id, array $claims ): ActorIdentity {
		$owned       = array();
		$staffed     = array();
		$permissions = array();
		$manager     = false;
		$narrowing   = array();
		foreach ( $claims as $claim ) {
			if ( ActorType::VendorOwner === $claim->type && null !== $claim->vendorId ) {
				$owned[ $claim->vendorId ] = true;
			} elseif ( ActorType::VendorStaff === $claim->type && null !== $claim->vendorId ) {
				$staffed[ $claim->vendorId ] = $claim->storePermissions ?? array();
			} elseif ( ActorType::MarketplaceManager === $claim->type ) {
				$manager     = true;
				$permissions = array_merge( $permissions, $claim->permissions );
			} elseif ( null === $claim->type && null !== $claim->managerScope ) {
				$narrowing[] = $claim->managerScope;
			}
		}

		$vendor_id = null;
		$store     = null;
		$member    = null;
		if ( 1 === count( $owned ) ) {
			$vendor_id = (int) array_key_first( $owned );
			$member    = ActorType::VendorOwner;
		} elseif ( array() === $owned && 1 === count( $staffed ) ) {
			$vendor_id = (int) array_key_first( $staffed );
			$store     = $staffed[ $vendor_id ];
			$member    = ActorType::VendorStaff;
		}

		$type = ActorType::Customer;
		foreach ( $claims as $claim ) {
			$counts = null !== $claim->type && ! in_array( $claim->type, array( ActorType::VendorOwner, ActorType::VendorStaff ), true );
			if ( $counts && $claim->type->rank() > $type->rank() ) {
				$type = $claim->type;
			}
		}
		if ( null !== $member && $member->rank() > $type->rank() ) {
			$type = $member;
		}

		$scope = null;
		if ( $manager && ActorType::MarketplaceManager === $type ) {
			$scope = VendorScope::all();
			foreach ( $narrowing as $narrower ) {
				$scope = $scope->intersect( $narrower );
			}
		}

		return new ActorIdentity( $type, $user_id, $vendor_id, array_values( array_unique( $permissions ) ), $store, $scope );
	}
}
