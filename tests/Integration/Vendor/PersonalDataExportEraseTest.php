<?php
/**
 * Exporting and erasing applicants' and vendors' personal data (TM-33, VEN-9, SEC-7).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Integration\Vendor;

use Mercatus\Core\Vendor\Infrastructure\ApplicationStore;
use Mercatus\Core\Vendor\Infrastructure\VendorPrivacy;
use WP_UnitTestCase;
use WP_User;

/**
 * Through WordPress's Export and Erase Personal Data tools: the export holds every attempt with its answers and
 * the vendor account. Erasing anonymises rejected and withdrawn attempts, keeps a pending attempt until it's
 * decided and a vendor's approved attempt and account, says why, and removes nothing more when repeated.
 */
final class PersonalDataExportEraseTest extends WP_UnitTestCase {

	use VendorTestHelpers;

	public function testTheExportHoldsEveryAttemptAndTheVendorAccount(): void {
		$user = $this->vendorAfterOneRejection();

		$export = self::exporter()( $user->user_email, 1 );
		$this->assertTrue( $export['done'] );
		$this->assertSame( array( 'mercatus-vendor-applications', 'mercatus-vendor-applications', 'mercatus-vendor-account' ), array_column( $export['data'], 'group_id' ) );

		$first = array_column( $export['data'][0]['data'], 'value', 'name' );
		$this->assertSame( 'Rare Maps', $first['Store name'] );
		$this->assertSame( '555 0100', $first['Contact phone'] );
		$this->assertSame( 'Add photos of your stock', $first['Reason'] );
		$this->assertSame( 'active', array_column( $export['data'][2]['data'], 'value', 'name' )['Status'] );
	}

	public function testErasingKeepsTheVendorAndAnonymisesTheRejectedAttempt(): void {
		$user = $this->vendorAfterOneRejection();

		$erased = self::eraser()( $user->user_email, 1 );

		$this->assertTrue( $erased['items_removed'] );
		$this->assertTrue( $erased['items_retained'] );
		$this->assertCount( 1, $erased['messages'] );
		list( $rejected, $approved ) = $this->attempts( $user );
		$this->assertSame( '[deleted]', $rejected->storeName );
		$this->assertNull( $rejected->reason );
		$this->assertSame( array( null ), array_unique( array_column( $rejected->answers, 'answer' ) ) );
		$this->assertSame( 'Contact phone', $rejected->answers[0]['label'], 'The questions stay; only the answers go.' );
		$this->assertSame( 'Rare Maps', $approved->storeName );
		$this->assertSame( 'active', $this->data( $this->asAdministrator( 'GET', '/vendors' ), 200 )['items'][0]['status'] );
		$this->assertFalse( self::eraser()( $user->user_email, 1 )['items_removed'], 'Nothing more to remove.' );
		$this->assertSame( 1, $this->anonymisedAudits( $rejected->id ) );
	}

	public function testAPendingAttemptIsKeptUntilItIsDecided(): void {
		$user = $this->applicant();
		$id   = (int) $this->data( $this->registerVendor( array( 'phone' => '555 0100' ) ), 201 )['application']['id'];
		$this->data( $this->asAdministrator( 'POST', '/vendor-applications/' . $id . '/reject', array( 'reason' => 'Too vague' ) ), 200 );
		$this->data( $this->registerVendor(), 201 );

		$erased = self::eraser()( $user->user_email, 1 );

		$this->assertTrue( $erased['items_removed'] );
		$this->assertTrue( $erased['items_retained'] );
		$this->assertStringContainsString( 'waiting for review', $erased['messages'][0] );
		list( $rejected, $pending ) = $this->attempts( $user );
		$this->assertSame( '[deleted]', $rejected->storeName );
		$this->assertSame( 'Corner Books', $pending->storeName );
	}

	public function testAnEmailWithoutAnAccountHasNothing(): void {
		$this->assertSame( array(), self::exporter()( 'nobody@example.org', 1 )['data'] );
		$erased = self::eraser()( 'nobody@example.org', 1 );
		$this->assertFalse( $erased['items_removed'] );
		$this->assertFalse( $erased['items_retained'] );
	}

	/**
	 * A vendor whose first attempt was rejected with a reason and whose second was approved.
	 */
	private function vendorAfterOneRejection(): WP_User {
		$user  = $this->applicant();
		$first = (int) $this->data(
			$this->registerVendor(
				array(
					'store_name' => 'Rare Maps',
					'phone'      => '555 0100',
				)
			),
			201
		)['application']['id'];
		$this->data( $this->asAdministrator( 'POST', '/vendor-applications/' . $first . '/reject', array( 'reason' => 'Add photos of your stock' ) ), 200 );
		$second = (int) $this->data( $this->registerVendor( array( 'store_name' => 'Rare Maps' ) ), 201 )['application']['id'];
		$this->data( $this->asAdministrator( 'POST', '/vendor-applications/' . $second . '/approve' ), 200 );

		return $user;
	}

	/**
	 * A signed-in customer.
	 */
	private function applicant(): WP_User {
		$user = get_userdata( $this->userAs( 'customer' ) );
		$this->assertInstanceOf( WP_User::class, $user );
		wp_set_current_user( $user->ID );

		return $user;
	}

	/**
	 * A user's attempts, oldest first, as stored.
	 *
	 * @param WP_User $user User.
	 * @return list<\Mercatus\Core\Vendor\Domain\VendorApplication>
	 */
	private function attempts( WP_User $user ): array {
		return self::kernel()->container()->get( ApplicationStore::class )->history( $user->ID );
	}

	/**
	 * Mercatus's exporter, as WordPress's Export Personal Data tool finds it.
	 */
	private static function exporter(): callable {
		return apply_filters( 'wp_privacy_personal_data_exporters', array() )[ VendorPrivacy::ID ]['callback']; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own filter.
	}

	/**
	 * Mercatus's eraser, as WordPress's Erase Personal Data tool finds it.
	 */
	private static function eraser(): callable {
		return apply_filters( 'wp_privacy_personal_data_erasers', array() )[ VendorPrivacy::ID ]['callback']; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own filter.
	}

	/**
	 * Audit entries recording an attempt's anonymisation.
	 *
	 * @param int $id Attempt ID.
	 */
	private function anonymisedAudits( int $id ): int {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE action = 'vendor_application.anonymised' AND resource_id = %s", $wpdb->prefix . 'mercatus_audit_log', (string) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test assertion.
	}
}
