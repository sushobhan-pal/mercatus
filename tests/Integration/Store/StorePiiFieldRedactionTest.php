<?php
/**
 * The public never sees private store fields (TM-10, SEC-2, STO-6).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Integration\Store;

use WP_UnitTestCase;

/**
 * Contact details appear publicly only when the vendor shows them; the street address, postcode and
 * coordinates never do. The owner sees everything.
 */
final class StorePiiFieldRedactionTest extends WP_UnitTestCase {

	use StoreTestHelpers;

	public function testThePublicSeesOnlyWhatTheVendorShows(): void {
		$vendor = $this->vendorWithStore();
		$this->data(
			$this->call(
				'PATCH',
				'/me/store',
				array(
					'visibility'    => 'public',
					'contact_email' => 'hello@raremaps.example',
					'contact_phone' => '+44 113 496 0000',
					'show_email'    => true,
					'address'       => array(
						'address_1' => '12 Market Street',
						'postcode'  => 'LS1 6DT',
						'city'      => 'Leeds',
						'country'   => 'GB',
					),
				),
				$this->etag( $this->call( 'GET', '/me/store' ) )
			),
			200
		);
		wp_set_current_user( 0 );

		foreach ( array( $this->data( $this->call( 'GET', '/stores/' . $vendor['slug'] ), 200 ), $this->data( $this->call( 'GET', '/stores' ), 200 )['items'][0] ) as $public ) {
			$json = (string) wp_json_encode( $public );
			$this->assertSame( array( 'email' => 'hello@raremaps.example' ), (array) $public['contact'] );
			$this->assertSame( array( 'Leeds', 'GB' ), array( $public['location']['city'], $public['location']['country'] ) );
			foreach ( array( '+44 113', 'Market Street', 'LS1 6DT', 'vendor_id', 'show_email', 'coordinates', 'visibility' ) as $private ) {
				$this->assertStringNotContainsString( $private, $json );
			}
		}

		wp_set_current_user( $vendor['user_id'] );
		$own = $this->data( $this->call( 'GET', '/me/store' ), 200 );
		$this->assertSame( array( '+44 113 496 0000', '12 Market Street' ), array( $own['contact_phone'], ( (array) $own['address'] )['address_1'] ) );
	}
}
