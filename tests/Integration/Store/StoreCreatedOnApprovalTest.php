<?php
/**
 * Approval creates the store (STO-1, STO-9, STO-13, VEN-5).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Integration\Store;

use Mercatus\Core\Store\Application\StoreSettings;
use Mercatus\Core\Store\Infrastructure\StoreEventSubscriber;
use Mercatus\Core\Shared\Actor\ActorRef;
use Mercatus\Core\Shared\Events\DomainEvent;
use WP_UnitTestCase;

/**
 * Exactly one store per approval, named and slugged after the requested store name, hidden until its vendor
 * opens it unless the marketplace says otherwise; a repeated delivery creates nothing more, and a taken slug
 * gets a number.
 */
final class StoreCreatedOnApprovalTest extends WP_UnitTestCase {

	use StoreTestHelpers;

	public function tear_down(): void {
		self::kernel()->settings()->set( StoreSettings::NEW_STORE_VISIBILITY, 'hidden' );
		parent::tear_down();
	}

	public function testApprovalCreatesAHiddenStoreFromTheRequestedName(): void {
		$vendor = $this->vendorWithStore( 'Rare Maps & Charts' );
		$store  = $this->data( $this->call( 'GET', '/me/store' ), 200 );

		$this->assertSame( 'Rare Maps & Charts', $store['name'] );
		$this->assertSame( 'rare-maps-charts', $vendor['slug'] );
		$this->assertSame( array( 'hidden', 'active', false ), array( $store['visibility'], $store['status'], $store['listed'] ) );
	}

	public function testNewStoresArePublicWhenTheMarketplaceSaysSo(): void {
		self::kernel()->settings()->set( StoreSettings::NEW_STORE_VISIBILITY, 'public' );

		$this->vendorWithStore();

		$this->assertTrue( $this->data( $this->call( 'GET', '/me/store' ), 200 )['listed'] );
	}

	public function testATakenSlugGetsANumber(): void {
		$first  = $this->vendorWithStore( 'Corner Books' );
		$second = $this->vendorWithStore( 'Corner Books' );

		$this->assertSame( array( 'corner-books', 'corner-books-2' ), array( $first['slug'], $second['slug'] ) );
	}

	public function testARepeatedApprovalEventCreatesNothingMore(): void {
		$vendor = $this->vendorWithStore();

		self::kernel()->container()->get( StoreEventSubscriber::class )->handle( self::approved( $vendor['vendor_id'] ) );

		global $wpdb;
		$this->assertSame( '1', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE vendor_id = %d', $wpdb->prefix . 'mercatus_stores', $vendor['vendor_id'] ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test assertion.
	}

	/**
	 * The approval event again.
	 *
	 * @param int $vendor_id Vendor.
	 */
	private static function approved( int $vendor_id ): DomainEvent {
		return new DomainEvent(
			'vendor.application.approved',
			'evt-again',
			new \DateTimeImmutable(),
			'vendor_application',
			'1',
			2,
			ActorRef::system(),
			'corr',
			array(
				'vendor_id'  => $vendor_id,
				'store_name' => 'Rare Maps',
			)
		);
	}
}
