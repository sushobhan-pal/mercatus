<?php
/**
 * Claims for test actors, until core.vendor and Pro provide real ones.
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Fixtures\Identity;

use Mercatus\Core\Contracts\Identity\ActorClaim;
use Mercatus\Core\Contracts\Identity\ClaimSource;
use Mercatus\Core\Contracts\Identity\VendorScope;

/**
 * Reads test user meta: `mercatus_test_vendor` (vendor ID) makes an owner, `mercatus_test_staff`
 * ("vendor ID|permission,permission") makes staff, and `mercatus_test_manager_scope` ("1,2") narrows a
 * manager's scope. The integration bootstrap registers it in the `pro.testactors` module.
 */
final class TestActorClaims implements ClaimSource {

	public const MODULE = 'pro.testactors';

	public function claims( int $user_id ): array {
		$claims = array();
		$vendor = get_user_meta( $user_id, 'mercatus_test_vendor', true );
		if ( is_numeric( $vendor ) ) {
			$claims[] = ActorClaim::vendorOwner( (int) $vendor );
		}
		$staff = get_user_meta( $user_id, 'mercatus_test_staff', true );
		if ( is_string( $staff ) && str_contains( $staff, '|' ) ) {
			list( $vendor_id, $permissions ) = explode( '|', $staff, 2 );
			$claims[]                        = ActorClaim::vendorStaff( (int) $vendor_id, array_values( array_filter( explode( ',', $permissions ) ) ) );
		}
		$scope = get_user_meta( $user_id, 'mercatus_test_manager_scope', true );
		if ( is_string( $scope ) && '' !== $scope ) {
			$claims[] = ActorClaim::managerScope( VendorScope::only( array_map( 'intval', explode( ',', $scope ) ) ) );
		}

		return $claims;
	}
}
