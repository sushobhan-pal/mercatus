<?php
/**
 * Who may see, change and moderate a product (TEST-3 row 6, SEC-2, SEC-4, CTL-4).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Unit\Catalog;

use Mercatus\Core\Catalog\Application\CatalogAccess;
use Mercatus\Core\Catalog\Application\CatalogPolicy;
use Mercatus\Core\Catalog\Domain\Product;
use Mercatus\Core\Contracts\Identity\AccessDecision;
use Mercatus\Core\Contracts\Identity\ActorIdentity;
use Mercatus\Core\Contracts\Identity\ActorType;
use Mercatus\Core\Contracts\Identity\VendorScope;
use PHPUnit\Framework\TestCase;

/**
 * Anyone sees an approved product; its vendor sees and changes it; administrators and managers holding
 * products.moderate within their scope moderate vendors' products. Everyone else is told it doesn't exist, and
 * the marketplace's own products are nobody's to change here.
 */
final class CatalogPolicyTest extends TestCase {

	private const VENDOR = 12;

	public function testAnyoneSeesAnApprovedProduct(): void {
		$policy = new CatalogPolicy();

		$this->assertSame( AccessDecision::Allowed, $policy->decide( ActorIdentity::anonymous(), CatalogPolicy::VIEW, self::product( Product::APPROVED ) ) );
		$this->assertSame( AccessDecision::Allowed, $policy->decide( new ActorIdentity( ActorType::Customer, 7 ), CatalogPolicy::VIEW, self::product( Product::APPROVED ) ) );
	}

	public function testAProductBuyersCannotSeeIsNotFoundToAnyoneButItsVendorAndModerators(): void {
		$policy = new CatalogPolicy();
		foreach ( array( Product::DRAFT, Product::PENDING, Product::REJECTED, Product::TAKEN_DOWN, Product::TRASHED ) as $status ) {
			$product = self::product( $status );
			$this->assertSame( AccessDecision::NotFound, $policy->decide( ActorIdentity::anonymous(), CatalogPolicy::VIEW, $product ), $status );
			$this->assertSame( AccessDecision::NotFound, $policy->decide( new ActorIdentity( ActorType::Customer, 7 ), CatalogPolicy::VIEW, $product ), $status );
			$this->assertSame( AccessDecision::NotFound, $policy->decide( new ActorIdentity( ActorType::VendorOwner, 8, 99 ), CatalogPolicy::VIEW, $product ), $status );
			$this->assertSame( AccessDecision::NotFound, $policy->decide( self::manager( array() ), CatalogPolicy::VIEW, $product ), $status . ', without products.moderate.' );
			$this->assertSame( AccessDecision::Allowed, $policy->decide( self::owner(), CatalogPolicy::VIEW, $product ), $status );
			$this->assertSame( AccessDecision::Allowed, $policy->decide( new ActorIdentity( ActorType::Administrator, 1 ), CatalogPolicy::VIEW, $product ), $status );
			$this->assertSame( AccessDecision::Allowed, $policy->decide( self::manager( array( CatalogAccess::MODERATE ) ), CatalogPolicy::VIEW, $product ), $status );
		}
	}

	public function testOnlyItsVendorChangesAProduct(): void {
		$policy = new CatalogPolicy();

		$this->assertSame( AccessDecision::Allowed, $policy->decide( self::owner(), CatalogPolicy::EDIT, self::product() ) );
		$this->assertSame( AccessDecision::NotFound, $policy->decide( new ActorIdentity( ActorType::VendorOwner, 8, 99 ), CatalogPolicy::EDIT, self::product() ), 'Another vendor.' );
		$this->assertSame( AccessDecision::NotFound, $policy->decide( new ActorIdentity( ActorType::Administrator, 1 ), CatalogPolicy::EDIT, self::product() ), 'Administrators moderate instead.' );
		$this->assertSame( AccessDecision::NotFound, $policy->decide( ActorIdentity::anonymous(), CatalogPolicy::EDIT, self::product() ) );
	}

	public function testModeratingNeedsThePermissionWithinTheManagersScope(): void {
		$policy = new CatalogPolicy();

		$this->assertSame( AccessDecision::Allowed, $policy->decide( new ActorIdentity( ActorType::Administrator, 1 ), CatalogPolicy::MODERATE, self::product() ) );
		$this->assertSame( AccessDecision::Allowed, $policy->decide( self::manager( array( CatalogAccess::MODERATE ) ), CatalogPolicy::MODERATE, self::product() ) );
		$this->assertSame( AccessDecision::Forbidden, $policy->decide( self::manager( array() ), CatalogPolicy::MODERATE, self::product() ) );
		$this->assertSame( AccessDecision::NotFound, $policy->decide( self::manager( array( CatalogAccess::MODERATE ), VendorScope::only( array( 99 ) ) ), CatalogPolicy::MODERATE, self::product() ) );
		$this->assertSame( AccessDecision::Forbidden, $policy->decide( self::owner(), CatalogPolicy::MODERATE, self::product() ), 'Refused inside their own store.' );
		$this->assertSame( AccessDecision::NotFound, $policy->decide( new ActorIdentity( ActorType::VendorOwner, 8, 99 ), CatalogPolicy::MODERATE, self::product() ) );
		$this->assertSame( AccessDecision::Unauthenticated, $policy->decide( ActorIdentity::anonymous(), CatalogPolicy::MODERATE, self::product() ) );
	}

	public function testTheMarketplacesOwnProductsAreManagedInWooCommerce(): void {
		$policy = new CatalogPolicy();
		$own    = self::product( Product::DRAFT, Product::SCOPE_MARKETPLACE, Product::MARKETPLACE );

		$this->assertSame( AccessDecision::NotFound, $policy->decide( new ActorIdentity( ActorType::Administrator, 1 ), CatalogPolicy::MODERATE, $own ) );
		$this->assertSame( AccessDecision::NotFound, $policy->decide( new ActorIdentity( ActorType::VendorOwner, 8, 0 ), CatalogPolicy::EDIT, $own ) );
		$this->assertSame( AccessDecision::Allowed, $policy->decide( ActorIdentity::anonymous(), CatalogPolicy::VIEW, self::product( Product::APPROVED, Product::SCOPE_MARKETPLACE, Product::MARKETPLACE ) ) );
	}

	public function testADeletedProductOrAnythingElseIsNotFound(): void {
		$policy = new CatalogPolicy();

		$this->assertSame( AccessDecision::NotFound, $policy->decide( self::owner(), CatalogPolicy::EDIT, self::product( Product::DELETED ) ) );
		$this->assertSame( AccessDecision::NotFound, $policy->decide( new ActorIdentity( ActorType::Administrator, 1 ), CatalogPolicy::VIEW, new \stdClass() ) );
		$this->assertSame( AccessDecision::NotFound, $policy->decide( self::owner(), 'publish-everything', self::product() ) );
	}

	/**
	 * A product of vendor 12.
	 *
	 * @param string $status Its state.
	 * @param string $scope  Its scope.
	 * @param int    $owner  Whose content it is.
	 */
	private static function product( string $status = Product::DRAFT, string $scope = Product::SCOPE_VENDOR, int $owner = self::VENDOR ): Product {
		return new Product( 3, 41, $scope, $owner, Product::SIMPLE, $status, '', null, '2026-11-11 10:00:00.000', '2026-11-11 10:00:00.000', 1 );
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
