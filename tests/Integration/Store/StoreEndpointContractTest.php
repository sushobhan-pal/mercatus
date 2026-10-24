<?php
/**
 * The store endpoints' contract (API-2 Stores, PLAT-9, STO-9, STO-13, STO-15).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Integration\Store;

use Mercatus\Core\Shared\Events\DomainEvent;
use Mercatus\Core\Store\Application\StoreSettings;
use WP_UnitTestCase;

/**
 * Changes need the ETag they were based on; opening a store lists it; the slug rules hold, and an
 * administrator may change a slug vendors can't. Each change is published.
 */
final class StoreEndpointContractTest extends WP_UnitTestCase {

	use StoreTestHelpers;

	/**
	 * Names of store events that reached WordPress.
	 *
	 * @var list<string>
	 */
	private array $events = array();

	public function set_up(): void {
		parent::set_up();
		add_action( 'mercatus_event', array( $this, 'onEvent' ) );
	}

	public function tear_down(): void {
		remove_action( 'mercatus_event', array( $this, 'onEvent' ) );
		self::kernel()->settings()->set( StoreSettings::VENDORS_CHANGE_SLUG, true );
		parent::tear_down();
	}

	public function testAChangeNeedsTheVersionItWasBasedOn(): void {
		$this->vendorWithStore();
		$etag = $this->etag( $this->call( 'GET', '/me/store' ) );

		$this->data( $this->call( 'PATCH', '/me/store', array( 'tagline' => 'Maps' ) ), 428 );
		$changed = $this->call( 'PATCH', '/me/store', array( 'tagline' => 'Maps' ), $etag );
		$this->assertSame( 'Maps', $this->data( $changed, 200 )['tagline'] );
		$this->assertNotSame( $etag, $this->etag( $changed ) );
		$this->assertSame( 'mercatus_version_conflict', $this->data( $this->call( 'PATCH', '/me/store', array( 'tagline' => 'Charts' ), $etag ), 409 )['code'] );
	}

	public function testOpeningAStoreListsIt(): void {
		$vendor       = $this->vendorWithStore();
		$this->events = array();

		$this->openMyStore();
		wp_set_current_user( 0 );

		$this->assertSame( array( $vendor['slug'] ), array_column( $this->data( $this->call( 'GET', '/stores' ), 200 )['items'], 'slug' ) );
		$this->assertSame( array( 'store.store.visibility_changed' ), $this->events );
	}

	public function testTheSlugRules(): void {
		$taken = $this->vendorWithStore( 'Taken Name' );
		$this->vendorWithStore( 'My Shop' );
		$etag = fn(): string => $this->etag( $this->call( 'GET', '/me/store' ) );

		$this->assertSame( 'mercatus_store_slug_taken', $this->data( $this->call( 'PATCH', '/me/store', array( 'slug' => $taken['slug'] ), $etag() ), 409 )['code'] );
		$this->assertSame( 'mercatus_store_slug_invalid', $this->data( $this->call( 'PATCH', '/me/store', array( 'slug' => '12345' ), $etag() ), 422 )['code'] );
		$this->assertSame( 'map-house', $this->data( $this->call( 'PATCH', '/me/store', array( 'slug' => 'map-house' ), $etag() ), 200 )['slug'] );
		$this->assertSame( 'mercatus_store_unknown', $this->data( $this->asAdministrator( 'GET', '/stores/my-shop' ), 404 )['code'], 'The old address stops working.' );

		self::kernel()->settings()->set( StoreSettings::VENDORS_CHANGE_SLUG, false );
		$this->assertSame( 'mercatus_store_slug_locked', $this->data( $this->call( 'PATCH', '/me/store', array( 'slug' => 'maps-again' ), $etag() ), 403 )['code'] );
	}

	public function testAnAdministratorChangesAnyStore(): void {
		$vendor = $this->vendorWithStore();
		self::kernel()->settings()->set( StoreSettings::VENDORS_CHANGE_SLUG, false );
		$admin = $this->userAs( 'administrator' );
		wp_set_current_user( $admin );
		$etag = $this->etag( $this->call( 'GET', '/stores/' . $vendor['store_id'] ) );

		$store = $this->data(
			$this->call(
				'PATCH',
				'/stores/' . $vendor['store_id'],
				array(
					'slug'       => 'renamed-by-admin',
					'visibility' => 'public',
				),
				$etag
			),
			200
		);

		$this->assertSame( array( 'renamed-by-admin', true ), array( $store['slug'], $store['listed'] ) );
	}

	public function testAnUnknownFieldIsRefused(): void {
		$this->vendorWithStore();

		$this->assertSame( 'mercatus_store_invalid', $this->data( $this->call( 'PATCH', '/me/store', array( 'address' => array( 'planet' => 'Mars' ) ), $this->etag( $this->call( 'GET', '/me/store' ) ) ), 422 )['code'] );
	}

	/**
	 * Records a store event's name.
	 *
	 * @param DomainEvent $event Event.
	 */
	public function onEvent( DomainEvent $event ): void {
		if ( str_starts_with( $event->name, 'store.' ) ) {
			$this->events[] = $event->name;
		}
	}
}
