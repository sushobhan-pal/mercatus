<?php
/**
 * The store's personal data in WordPress's privacy tools (SEC-7).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Integration\Store;

use Mercatus\Core\Store\Infrastructure\StoreHooks;
use Mercatus\Core\Store\Infrastructure\Stores;
use WP_UnitTestCase;

/**
 * The export holds the store's name, address and contact details; erasing keeps an open store's, because its
 * buyers rely on them, and clears a closed store's.
 */
final class StorePersonalDataTest extends WP_UnitTestCase {

	use StoreTestHelpers;

	public function testTheExportAndAnOpenStoreKeepingItsDetails(): void {
		$vendor = $this->vendorWithStore();
		$this->data( $this->call( 'PATCH', '/me/store', array( 'contact_phone' => '+44 113 496 0000' ), $this->etag( $this->call( 'GET', '/me/store' ) ) ), 200 );
		$email = get_userdata( $vendor['user_id'] )->user_email ?? '';

		$export = self::tool( 'wp_privacy_personal_data_exporters' )( $email, 1 );
		$fields = array_column( $export['data'][0]['data'], 'value', 'name' );
		$this->assertSame( array( 'Rare Maps', '+44 113 496 0000' ), array( $fields['Store name'], $fields['Contact phone'] ) );

		$erased = self::tool( 'wp_privacy_personal_data_erasers' )( $email, 1 );
		$this->assertSame( array( false, true ), array( $erased['items_removed'], $erased['items_retained'] ) );
	}

	public function testAClosedStoresDetailsAreCleared(): void {
		$vendor = $this->vendorWithStore();
		$this->data( $this->call( 'PATCH', '/me/store', array( 'contact_phone' => '+44 113 496 0000' ), $this->etag( $this->call( 'GET', '/me/store' ) ) ), 200 );
		$stores = self::kernel()->container()->get( Stores::class );
		$stores->followVendor( $vendor['vendor_id'], 'closed' );

		$this->assertTrue( $stores->erasePersonalData( $vendor['vendor_id'] ) );
		$this->assertSame( '', $stores->export( $vendor['vendor_id'] )['contact_phone'] ?? null );
	}

	/**
	 * A WordPress privacy tool's callback for the store.
	 *
	 * @param string $filter Which tool.
	 */
	private static function tool( string $filter ): callable {
		$tools = 'wp_privacy_personal_data_exporters' === $filter
			? apply_filters( 'wp_privacy_personal_data_exporters', array() ) // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own filter.
			: apply_filters( 'wp_privacy_personal_data_erasers', array() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own filter.

		return $tools[ StoreHooks::PRIVACY_ID ]['callback'];
	}
}
