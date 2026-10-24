<?php
/**
 * Who may use which access level and vendor resource (TEST-3 row 4, SEC-4, IDN-4, IDN-15, TM-5, TM-6).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Unit\Identity;

use Mercatus\Core\Contracts\Http\Access;
use Mercatus\Core\Contracts\Identity\ActorIdentity;
use Mercatus\Core\Contracts\Identity\ActorType;
use Mercatus\Core\Contracts\Identity\VendorScope;
use Mercatus\Core\Contracts\Identity\AccessDecision;
use Mercatus\Core\Identity\Domain\PolicyEngine;
use PHPUnit\Framework\TestCase;

/**
 * Every actor against every access level, with and without a named permission, and against vendor
 * resources inside and outside their store or scope.
 */
final class PolicyEngineMatrixTest extends TestCase {

	private const PERMISSION = 'kernel.health.view';

	/**
	 * The test actors.
	 *
	 * @return array<string, ActorIdentity>
	 */
	private static function actors(): array {
		return array(
			'anonymous'     => ActorIdentity::anonymous(),
			'customer'      => new ActorIdentity( ActorType::Customer, 2 ),
			'owner'         => new ActorIdentity( ActorType::VendorOwner, 3, 12 ),
			'staff'         => new ActorIdentity( ActorType::VendorStaff, 4, 12, storePermissions: array( 'orders.view' ) ),
			'manager'       => new ActorIdentity( ActorType::MarketplaceManager, 5, permissions: array( self::PERMISSION ), managerScope: VendorScope::only( array( 12 ) ) ),
			'administrator' => new ActorIdentity( ActorType::Administrator, 6 ),
		);
	}

	/**
	 * Expected decisions: actor => access level => [without permission, with permission].
	 *
	 * @return array<string, array{string, Access, string|null, AccessDecision}>
	 */
	public static function accessCases(): array {
		$a = AccessDecision::Allowed;
		$u = AccessDecision::Unauthenticated;
		$f = AccessDecision::Forbidden;
		// Columns: public, authenticated, authenticated + permission, tenant, tenant + orders.view, admin, admin + permission.
		$table   = array(
			'anonymous'     => array( $a, $u, $u, $u, $u, $u, $u ),
			'customer'      => array( $a, $a, $f, $f, $f, $f, $f ),
			'owner'         => array( $a, $a, $f, $a, $a, $f, $f ),
			'staff'         => array( $a, $a, $f, $a, $a, $f, $f ),
			'manager'       => array( $a, $a, $a, $f, $f, $f, $a ),
			'administrator' => array( $a, $a, $a, $f, $f, $a, $a ),
		);
		$columns = array(
			array( Access::Open, null ),
			array( Access::Authenticated, null ),
			array( Access::Authenticated, self::PERMISSION ),
			array( Access::Tenant, null ),
			array( Access::Tenant, 'orders.view' ),
			array( Access::Admin, null ),
			array( Access::Admin, self::PERMISSION ),
		);

		$cases = array();
		foreach ( $table as $actor => $decisions ) {
			foreach ( $columns as $index => $column ) {
				$cases[ sprintf( '%s, %s%s', $actor, $column[0]->value, null === $column[1] ? '' : ' + ' . $column[1] ) ] = array( $actor, $column[0], $column[1], $decisions[ $index ] );
			}
		}

		return $cases;
	}

	/**
	 * Route and menu access.
	 *
	 * @dataProvider accessCases
	 * @param string         $actor      Actor key.
	 * @param Access         $access     Access level.
	 * @param string|null    $permission Named permission.
	 * @param AccessDecision $expected   Decision.
	 */
	public function testDecidesAccess( string $actor, Access $access, ?string $permission, AccessDecision $expected ): void {
		$this->assertSame( $expected, PolicyEngine::access( self::actors()[ $actor ], $access, $permission ) );
	}

	public function testStaffNeedTheirOwnersGrantForAPermissionInTheStore(): void {
		$this->assertSame( AccessDecision::Forbidden, PolicyEngine::access( self::actors()['staff'], Access::Tenant, 'orders.refund' ) );
	}

	/**
	 * Vendor resources: [actor, resource's vendor, permission, decision].
	 *
	 * @return array<string, array{string, int, string|null, AccessDecision}>
	 */
	public static function resourceCases(): array {
		return array(
			'anonymous'                        => array( 'anonymous', 12, null, AccessDecision::Unauthenticated ),
			'customer'                         => array( 'customer', 12, null, AccessDecision::NotFound ),
			'owner, own store'                 => array( 'owner', 12, 'orders.refund', AccessDecision::Allowed ),
			'owner, another store'             => array( 'owner', 13, null, AccessDecision::NotFound ),
			'staff, granted'                   => array( 'staff', 12, 'orders.view', AccessDecision::Allowed ),
			'staff, not granted'               => array( 'staff', 12, 'orders.refund', AccessDecision::Forbidden ),
			'staff, another store'             => array( 'staff', 13, 'orders.view', AccessDecision::NotFound ),
			'manager, in scope'                => array( 'manager', 12, self::PERMISSION, AccessDecision::Allowed ),
			'manager, in scope, not permitted' => array( 'manager', 12, 'withdrawals.approve', AccessDecision::Forbidden ),
			'manager, outside scope'           => array( 'manager', 13, self::PERMISSION, AccessDecision::NotFound ),
			'administrator'                    => array( 'administrator', 13, 'withdrawals.approve', AccessDecision::Allowed ),
		);
	}

	/**
	 * Another store's resource, or one outside a manager's scope, is not found (SEC-4).
	 *
	 * @dataProvider resourceCases
	 * @param string         $actor      Actor key.
	 * @param int            $vendor_id  The resource's vendor.
	 * @param string|null    $permission Permission the action needs.
	 * @param AccessDecision $expected   Decision.
	 */
	public function testDecidesVendorResources( string $actor, int $vendor_id, ?string $permission, AccessDecision $expected ): void {
		$this->assertSame( $expected, PolicyEngine::vendorResource( self::actors()[ $actor ], $vendor_id, $permission ) );
	}

	public function testAClientsScopesCapTheRequest(): void {
		$client = new ActorIdentity( ActorType::Administrator, 6, apiClientId: 3, clientScopes: array( 'read', self::PERMISSION ) );
		$writer = new ActorIdentity( ActorType::Customer, 2, apiClientId: 4, clientScopes: array( 'write' ) );

		$this->assertSame( AccessDecision::Allowed, PolicyEngine::scope( self::actors()['administrator'], 'anything.at_all', true ), 'No client, no cap.' );
		$this->assertSame( AccessDecision::Allowed, PolicyEngine::scope( $client, self::PERMISSION, false ) );
		$this->assertSame( AccessDecision::Forbidden, PolicyEngine::scope( $client, null, true ), 'Read-only can\'t write.' );
		$this->assertSame( AccessDecision::Forbidden, PolicyEngine::scope( $client, 'kernel.modules.manage', false ), 'A permission outside the scopes.' );
		$this->assertSame( AccessDecision::Allowed, PolicyEngine::scope( $writer, null, false ), 'Write includes read.' );
	}

	public function testDecisionsMapToStatuses(): void {
		$this->assertSame( array( 200, 401, 403, 404 ), array_map( static fn( AccessDecision $decision ): int => $decision->status(), AccessDecision::cases() ) );
	}
}
