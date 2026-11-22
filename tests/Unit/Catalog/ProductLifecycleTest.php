<?php
/**
 * A product's life and the WooCommerce status it gives (CTL-5, CTL-13, CTL-20).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Unit\Catalog;

use Mercatus\Core\Catalog\Domain\Offer;
use Mercatus\Core\Catalog\Domain\Product;
use Mercatus\Core\Catalog\Domain\ProductLifecycle;
use Mercatus\Core\Catalog\Domain\WooStatus;
use Mercatus\Core\Shared\Errors\DomainError;
use PHPUnit\Framework\TestCase;

/**
 * Publishing goes through review when it applies, and always after a take-down; unpublishing pauses an
 * approved product's offer or withdraws one waiting for review. WooCommerce publishes a product only while
 * buyers may see it.
 */
final class ProductLifecycleTest extends TestCase {

	public function testPublishingANewProductGoesThroughReviewOnlyWhenItApplies(): void {
		$this->assertSame( Product::PENDING, ProductLifecycle::published( Product::DRAFT, true ) );
		$this->assertSame( Product::APPROVED, ProductLifecycle::published( Product::DRAFT, false ) );
		$this->assertSame( Product::PENDING, ProductLifecycle::published( Product::REJECTED, true ) );
		$this->assertSame( Product::APPROVED, ProductLifecycle::published( Product::REJECTED, false ) );
	}

	public function testAnApprovedProductRepublishesWithoutReviewButATakenDownOneAlwaysGoesBack(): void {
		$this->assertSame( Product::APPROVED, ProductLifecycle::published( Product::APPROVED, true ) );
		$this->assertSame( Product::PENDING, ProductLifecycle::published( Product::TAKEN_DOWN, false ) );
		$this->assertSame( Product::PENDING, ProductLifecycle::published( Product::PENDING, false ), 'Already waiting.' );
	}

	public function testATrashedProductCannotBePublished(): void {
		$this->expectException( DomainError::class );
		ProductLifecycle::published( Product::TRASHED, false );
	}

	public function testUnpublishingWithdrawsAPendingProductAndKeepsTheRest(): void {
		$this->assertSame( Product::DRAFT, ProductLifecycle::unpublished( Product::PENDING ) );
		$this->assertSame( Product::APPROVED, ProductLifecycle::unpublished( Product::APPROVED ) );
		$this->assertSame( Product::TAKEN_DOWN, ProductLifecycle::unpublished( Product::TAKEN_DOWN ) );
	}

	public function testTheMachineAllowsOnlyItsMoves(): void {
		$machine = ProductLifecycle::machine();

		$this->assertTrue( $machine->allows( Product::PENDING, Product::REJECTED ) );
		$this->assertTrue( $machine->allows( Product::APPROVED, Product::TAKEN_DOWN ) );
		$this->assertTrue( $machine->allows( Product::TRASHED, Product::DRAFT ) );
		$this->assertFalse( $machine->allows( Product::DRAFT, Product::REJECTED ), 'Only a pending product is rejected.' );
		$this->assertFalse( $machine->allows( Product::DRAFT, Product::TAKEN_DOWN ), 'Only an approved product is taken down.' );
		$this->assertFalse( $machine->allows( Product::DELETED, Product::DRAFT ) );
	}

	public function testTheVendorSeesPublishedOrUnpublishedForAnApprovedProduct(): void {
		$this->assertSame( 'published', ProductLifecycle::visible( Product::APPROVED, Offer::ACTIVE ) );
		$this->assertSame( 'unpublished', ProductLifecycle::visible( Product::APPROVED, Offer::PAUSED ) );
		$this->assertSame( Product::PENDING, ProductLifecycle::visible( Product::PENDING, Offer::ACTIVE ) );
		$this->assertFalse( ProductLifecycle::editable( Product::TRASHED ) );
		$this->assertTrue( ProductLifecycle::editable( Product::TAKEN_DOWN ) );
	}

	/**
	 * CTL-13's table.
	 *
	 * @return array<string, array{string, string, bool, string}>
	 */
	public static function wooStatuses(): array {
		return array(
			'draft'        => array( Product::DRAFT, Offer::PAUSED, true, 'draft' ),
			'rejected'     => array( Product::REJECTED, Offer::ACTIVE, true, 'draft' ),
			'pending'      => array( Product::PENDING, Offer::ACTIVE, true, 'pending' ),
			'published'    => array( Product::APPROVED, Offer::ACTIVE, true, 'publish' ),
			'unpublished'  => array( Product::APPROVED, Offer::PAUSED, true, 'private' ),
			'store closed' => array( Product::APPROVED, Offer::ACTIVE, false, 'private' ),
			'taken down'   => array( Product::TAKEN_DOWN, Offer::ACTIVE, true, 'private' ),
			'trashed'      => array( Product::TRASHED, Offer::ACTIVE, true, 'trash' ),
		);
	}

	/**
	 * WooCommerce publishes a product only while buyers may see it (CTL-13).
	 *
	 * @dataProvider wooStatuses
	 * @param string $status       Product state.
	 * @param string $offer_status Offer state.
	 * @param bool   $store_open   Whether the store is open.
	 * @param string $expected     WooCommerce status.
	 */
	public function testWooCommerceStatusFollowsTheTable( string $status, string $offer_status, bool $store_open, string $expected ): void {
		$this->assertSame( $expected, WooStatus::for( $status, $offer_status, $store_open ) );
		$this->assertSame( 'publish' === $expected, WooStatus::visible( $status, $offer_status, $store_open ) );
	}
}
