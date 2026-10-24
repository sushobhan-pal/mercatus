<?php
/**
 * Each step sends its WooCommerce email once (VEN-7, ARCH-12).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Integration\Vendor;

use WP_UnitTestCase;
use WP_User;

/**
 * The administrator hears about each application; the applicant hears the decision, with the reason when
 * rejected; the vendor hears about a suspension, with the reason, and about being reinstated.
 */
final class ApplicationEmailsTest extends WP_UnitTestCase {

	use VendorTestHelpers;

	public function set_up(): void {
		parent::set_up();
		reset_phpmailer_instance();
	}

	public function testTheAdministratorAndTheApplicantHearAboutEachDecision(): void {
		$applicant = $this->customer();
		$id        = (int) $this->data( $this->registerVendor( array( 'store_name' => 'Rare Maps' ) ), 201 )['application']['id'];
		$this->data( $this->asAdministrator( 'POST', '/vendor-applications/' . $id . '/reject', array( 'reason' => 'Add photos of your stock' ) ), 200 );

		$sent = $this->sent();
		$this->assertCount( 2, $sent );
		$this->assertSame( get_option( 'admin_email' ), $sent[0]['to'] );
		$this->assertStringContainsString( 'Rare Maps', $sent[0]['subject'] );
		$this->assertSame( $applicant->user_email, $sent[1]['to'] );
		$this->assertStringContainsString( 'Add photos of your stock', $sent[1]['body'] );
	}

	public function testTheVendorHearsAboutApprovalSuspensionAndReinstatement(): void {
		$vendor = $this->customer();
		$id     = (int) $this->data( $this->registerVendor( array( 'store_name' => 'Rare Maps' ) ), 201 )['application']['id'];
		$this->data( $this->asAdministrator( 'POST', '/vendor-applications/' . $id . '/approve' ), 200 );
		$vendor_id = (int) $this->data( $this->asAdministrator( 'GET', '/vendors' ), 200 )['items'][0]['id'];
		$this->data( $this->asAdministrator( 'POST', '/vendors/' . $vendor_id . '/suspend', array( 'reason' => 'Late shipments' ) ), 200 );
		$this->data( $this->asAdministrator( 'POST', '/vendors/' . $vendor_id . '/reinstate' ), 200 );

		$to_vendor = array_values( array_filter( $this->sent(), static fn( array $mail ): bool => $mail['to'] === $vendor->user_email ) );
		$this->assertCount( 3, $to_vendor, 'Approved, suspended and reinstated, once each.' );
		$this->assertStringContainsString( 'approved', $to_vendor[0]['subject'] );
		$this->assertStringContainsString( 'Late shipments', $to_vendor[1]['body'] );
		$this->assertStringContainsString( 'back', $to_vendor[2]['subject'] );
	}

	public function testAnAdministratorCanSwitchAnEmailOff(): void {
		update_option( 'woocommerce_mercatus_vendor_application_received_settings', array( 'enabled' => 'no' ) );
		WC()->mailer()->init();
		$this->customer();

		$this->data( $this->registerVendor(), 201 );

		$this->assertSame( array(), $this->sent() );
	}

	/**
	 * A signed-in customer.
	 */
	private function customer(): WP_User {
		$user = get_userdata( $this->userAs( 'customer' ) );
		$this->assertInstanceOf( WP_User::class, $user );
		wp_set_current_user( $user->ID );

		return $user;
	}

	/**
	 * The emails sent so far.
	 *
	 * @return list<array{to: string, subject: string, body: string}>
	 */
	private function sent(): array {
		$mailer = tests_retrieve_phpmailer_instance();
		$this->assertInstanceOf( \MockPHPMailer::class, $mailer );
		$mails = array();
		foreach ( $mailer->mock_sent as $mail ) {
			$mails[] = array(
				'to'      => (string) ( $mail['to'][0][0] ?? '' ),
				'subject' => (string) $mail['subject'],
				'body'    => (string) $mail['body'],
			);
		}

		return $mails;
	}
}
