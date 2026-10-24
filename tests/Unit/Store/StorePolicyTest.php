<?php
/**
 * Who may see and change a store (SEC-2, SEC-4, STO-3).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Unit\Store;

use Mercatus\Core\Contracts\Identity\AccessDecision;
use Mercatus\Core\Contracts\Identity\ActorIdentity;
use Mercatus\Core\Contracts\Identity\ActorType;
use Mercatus\Core\Contracts\Identity\VendorScope;
use Mercatus\Core\Store\Application\StoreAccess;
use Mercatus\Core\Store\Application\StorePolicy;
use Mercatus\Core\Store\Domain\Store;
use PHPUnit\Framework\TestCase;

/**
 * Anyone sees a listed store; a hidden or suspended one is seen only by its owner, administrators and managers
 * holding stores.manage, and everyone else is told it doesn't exist. Only the owner edits through /me; managing
 * any store needs the permission, within a manager's scope.
 */
final class StorePolicyTest extends TestCase {

	private const VENDOR = 12;

	public function testAnyoneSeesAListedStore(): void {
		$policy = new StorePolicy();

		$this->assertSame( AccessDecision::Allowed, $policy->decide( ActorIdentity::anonymous(), StorePolicy::VIEW, self::store() ) );
		$this->assertSame( AccessDecision::Allowed, $policy->decide( new ActorIdentity( ActorType::Customer, 7 ), StorePolicy::VIEW, self::store() ) );
	}

	public function testAnUnlistedStoreIsNotFoundToAnyoneButItsOwnerAndManagers(): void {
		$policy = new StorePolicy();
		$hidden = self::store( Store::HIDDEN );

		foreach ( array( $hidden, self::store( Store::PUBLIC, Store::SUSPENDED ) ) as $store ) {
			$this->assertSame( AccessDecision::NotFound, $policy->decide( ActorIdentity::anonymous(), StorePolicy::VIEW, $store ) );
			$this->assertSame( AccessDecision::NotFound, $policy->decide( new ActorIdentity( ActorType::Customer, 7 ), StorePolicy::VIEW, $store ) );
			$this->assertSame( AccessDecision::NotFound, $policy->decide( new ActorIdentity( ActorType::VendorOwner, 8, 99 ), StorePolicy::VIEW, $store ) );
			$this->assertSame( AccessDecision::NotFound, $policy->decide( self::manager( array() ), StorePolicy::VIEW, $store ), 'Without stores.manage.' );
			$this->assertSame( AccessDecision::Allowed, $policy->decide( self::owner(), StorePolicy::VIEW, $store ) );
			$this->assertSame( AccessDecision::Allowed, $policy->decide( new ActorIdentity( ActorType::Administrator, 1 ), StorePolicy::VIEW, $store ) );
			$this->assertSame( AccessDecision::Allowed, $policy->decide( self::manager( array( StoreAccess::MANAGE ) ), StorePolicy::VIEW, $store ) );
		}
	}

	public function testOnlyTheOwnerEditsTheirStore(): void {
		$policy = new StorePolicy();

		$this->assertSame( AccessDecision::Allowed, $policy->decide( self::owner(), StorePolicy::EDIT, self::store() ) );
		$this->assertSame( AccessDecision::NotFound, $policy->decide( new ActorIdentity( ActorType::VendorOwner, 8, 99 ), StorePolicy::EDIT, self::store() ) );
		$this->assertSame( AccessDecision::Unauthenticated, $policy->decide( ActorIdentity::anonymous(), StorePolicy::EDIT, self::store() ) );
	}

	public function testManagingAStoreNeedsThePermissionWithinTheManagersScope(): void {
		$policy = new StorePolicy();

		$this->assertSame( AccessDecision::Allowed, $policy->decide( new ActorIdentity( ActorType::Administrator, 1 ), StorePolicy::MANAGE, self::store() ) );
		$this->assertSame( AccessDecision::Allowed, $policy->decide( self::manager( array( StoreAccess::MANAGE ) ), StorePolicy::MANAGE, self::store() ) );
		$this->assertSame( AccessDecision::Forbidden, $policy->decide( self::manager( array() ), StorePolicy::MANAGE, self::store() ) );
		$this->assertSame( AccessDecision::NotFound, $policy->decide( self::manager( array( StoreAccess::MANAGE ), VendorScope::only( array( 99 ) ) ), StorePolicy::MANAGE, self::store() ) );
	}

	public function testAnythingElseIsNotFound(): void {
		$this->assertSame( AccessDecision::NotFound, ( new StorePolicy() )->decide( new ActorIdentity( ActorType::Administrator, 1 ), StorePolicy::VIEW, new \stdClass() ) );
	}

	/**
	 * A store of vendor 12.
	 *
	 * @param string $visibility Visibility.
	 * @param string $status     Status.
	 */
	private static function store( string $visibility = Store::PUBLIC, string $status = Store::ACTIVE ): Store {
		return new Store( 3, self::VENDOR, 'Rare Maps', 'rare-maps', array(), false, false, null, null, $visibility, $status, '2026-10-24 10:00:00.000', '2026-10-24 10:00:00.000', 1 );
	}

	/**
	 * Vendor 12's owner.
	 */
	private static function owner(): ActorIdentity {
		return new ActorIdentity( ActorType::VendorOwner, 7, self::VENDOR );
	}

	/**
	 * A manager.
	 *
	 * @param list<string>     $permissions Permissions.
	 * @param VendorScope|null $scope       Scope; all vendors by default.
	 */
	private static function manager( array $permissions, ?VendorScope $scope = null ): ActorIdentity {
		return new ActorIdentity( ActorType::MarketplaceManager, 3, permissions: $permissions, managerScope: $scope ?? VendorScope::all() );
	}
}
