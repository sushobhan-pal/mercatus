<?php
/**
 * Visitors and customers register as vendors; higher actor types are refused (VEN-12).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Integration\Vendor;

use WP_UnitTestCase;
use WP_User;

/**
 * A visitor gets a WooCommerce customer account and is signed in; a signed-in customer applies directly;
 * administrators, managers and existing vendors can't; a taken email asks the person to sign in.
 */
final class VendorRegistrationTest extends WP_UnitTestCase {

	use VendorTestHelpers;

	public function set_up(): void {
		parent::set_up();
		add_filter( 'send_auth_cookies', '__return_false' );
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		remove_filter( 'send_auth_cookies', '__return_false' );
		parent::tear_down();
	}

	public function testAVisitorGetsACustomerAccountAndIsSignedIn(): void {
		$response = $this->registerVendor(
			array(
				'email'      => 'new.vendor@example.org',
				'first_name' => 'Ada',
				'password'   => 'a strong passphrase 42',
			)
		);
		$data     = $this->data( $response, 201 );

		$this->assertTrue( $data['account_created'] );
		$user = get_user_by( 'email', 'new.vendor@example.org' );
		$this->assertInstanceOf( WP_User::class, $user );
		$this->assertSame( array( 'customer' ), $user->roles );
		$this->assertSame( $user->ID, get_current_user_id() );
		$this->assertSame( $user->ID, $data['application']['user_id'] );
		$this->assertNotEmpty( $response->get_headers()['X-WP-Nonce'] ?? '' );
	}

	public function testATakenEmailAsksThePersonToSignIn(): void {
		$existing = get_userdata( $this->userAs( 'customer' ) );
		$this->assertInstanceOf( WP_User::class, $existing );

		$data = $this->data(
			$this->registerVendor(
				array(
					'email'    => $existing->user_email,
					'password' => 'whatever 123',
				)
			),
			409
		);

		$this->assertSame( 'mercatus_email_registered', $data['code'] );
	}

	public function testAVisitorNeedsAPasswordUnlessWooCommerceGeneratesOne(): void {
		update_option( 'woocommerce_registration_generate_password', 'no' );

		$data = $this->data( $this->registerVendor( array( 'email' => 'no.password@example.org' ) ), 422 );

		$this->assertSame( 'password', ( (array) $data['details'] )['field'] ?? null );
	}

	public function testAccountsAboveACustomerCannotApply(): void {
		foreach ( array( 'administrator', 'marketplace_manager' ) as $actor ) {
			wp_set_current_user( $this->userAs( $actor ) );
			$data = $this->data( $this->registerVendor(), 403 );
			$this->assertSame( 'mercatus_vendor_application_not_allowed', $data['code'], $actor );
		}
	}

	public function testAVendorOrAPendingApplicantCannotApplyAgain(): void {
		wp_set_current_user( $this->userAs( 'customer' ) );
		$id = (int) $this->data( $this->registerVendor(), 201 )['application']['id'];

		$this->assertSame( 'mercatus_application_pending', $this->data( $this->registerVendor(), 409 )['code'] );

		$this->data( $this->asAdministrator( 'POST', '/vendor-applications/' . $id . '/approve' ), 200 );
		$this->assertSame( 'mercatus_already_vendor', $this->data( $this->registerVendor(), 409 )['code'] );
	}
}
