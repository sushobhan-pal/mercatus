<?php
/**
 * From application to vendor, and through suspension (TEST-3 row 5, VEN-1, VEN-3, VEN-17, TM-8).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Integration\Vendor;

use Mercatus\Core\Contracts\Identity\ActorType;
use Mercatus\Core\Contracts\Identity\CurrentActor;
use Mercatus\Core\Vendor\Application\VendorSettings;
use WP_UnitTestCase;

/**
 * Approval creates an active vendor and makes the user a vendor owner at once; suspension takes it away at
 * once; deleting the user closes the vendor. Every step publishes its event.
 */
final class VendorApplicationWorkflowTest extends WP_UnitTestCase {

	use VendorTestHelpers;

	/**
	 * Names of events that reached WordPress.
	 *
	 * @var list<string>
	 */
	private array $events = array();

	public function set_up(): void {
		parent::set_up();
		add_action( 'mercatus_event', array( $this, 'record' ) );
	}

	public function tear_down(): void {
		remove_action( 'mercatus_event', array( $this, 'record' ) );
		self::kernel()->settings()->set( VendorSettings::AUTO_APPROVE, false );
		parent::tear_down();
	}

	/**
	 * Records an event's name.
	 *
	 * @param \Mercatus\Core\Shared\Events\DomainEvent $event Event.
	 */
	public function record( \Mercatus\Core\Shared\Events\DomainEvent $event ): void {
		$this->events[] = $event->name;
	}

	public function testApprovalMakesAVendorOwnerAtOnce(): void {
		$user = $this->userAs( 'customer' );
		wp_set_current_user( $user );
		$application = $this->data( $this->registerVendor(), 201 )['application'];
		$this->assertSame( array( 'pending', 1 ), array( $application['status'], $application['attempt'] ) );
		$this->assertSame( ActorType::Customer, $this->identity()->type );

		$approved = $this->data( $this->asAdministrator( 'POST', '/vendor-applications/' . $application['id'] . '/approve' ), 200 );

		$this->assertSame( 'approved', $approved['status'] );
		$identity = $this->identity();
		$this->assertSame( ActorType::VendorOwner, $identity->type );
		$this->assertNotNull( $identity->vendorId );
		$this->assertSame( array( 'vendor.application.submitted', 'vendor.application.approved' ), $this->events );
	}

	public function testSuspensionTakesTheStoreAwayAtOnceAndReinstatingGivesItBack(): void {
		$vendor_id = $this->approvedVendor();

		$this->data( $this->asAdministrator( 'POST', '/vendors/' . $vendor_id . '/suspend', array( 'reason' => 'Unpaid fees' ) ), 200 );
		$this->assertSame( ActorType::Customer, $this->identity()->type );
		$this->assertNull( $this->identity()->vendorId );

		$this->data( $this->asAdministrator( 'POST', '/vendors/' . $vendor_id . '/reinstate' ), 200 );
		$this->assertSame( ActorType::VendorOwner, $this->identity()->type );
		$this->assertContains( 'vendor.account.suspended', $this->events );
		$this->assertContains( 'vendor.account.reinstated', $this->events );
	}

	public function testARejectionNeedsAReasonAndIsFinal(): void {
		wp_set_current_user( $this->userAs( 'customer' ) );
		$id = (int) $this->data( $this->registerVendor(), 201 )['application']['id'];

		$this->assertSame( 400, $this->asAdministrator( 'POST', '/vendor-applications/' . $id . '/reject' )->get_status(), 'The reason is a required argument.' );
		$rejected = $this->data( $this->asAdministrator( 'POST', '/vendor-applications/' . $id . '/reject', array( 'reason' => 'Add your tax details' ) ), 200 );
		$this->assertSame( array( 'rejected', 'Add your tax details' ), array( $rejected['status'], $rejected['reason'] ) );

		$again = $this->data( $this->asAdministrator( 'POST', '/vendor-applications/' . $id . '/approve' ), 409 );
		$this->assertSame( 'mercatus_transition_not_allowed', $again['code'] );
	}

	public function testAutomaticApprovalNeedsNoReviewer(): void {
		self::kernel()->settings()->set( VendorSettings::AUTO_APPROVE, true );
		wp_set_current_user( $this->userAs( 'customer' ) );

		$application = $this->data( $this->registerVendor(), 201 )['application'];

		$this->assertSame( array( 'approved', 0 ), array( $application['status'], $application['decided_by'] ) );
		$this->assertSame( ActorType::VendorOwner, $this->identity()->type );
	}

	public function testDeletingTheUserClosesTheVendor(): void {
		$vendor_id = $this->approvedVendor();
		require_once ABSPATH . 'wp-admin/includes/user.php';

		wp_delete_user( get_current_user_id() );

		$vendors = $this->data( $this->asAdministrator( 'GET', '/vendors', array( 'status' => 'closed' ) ), 200 );
		$this->assertSame( array( $vendor_id ), array_column( $vendors['items'], 'id' ) );
		$this->assertContains( 'vendor.account.closed', $this->events );
	}

	/**
	 * A signed-in customer who applied and was approved; returns the vendor ID.
	 */
	private function approvedVendor(): int {
		wp_set_current_user( $this->userAs( 'customer' ) );
		$id = (int) $this->data( $this->registerVendor(), 201 )['application']['id'];
		$this->data( $this->asAdministrator( 'POST', '/vendor-applications/' . $id . '/approve' ), 200 );
		$vendor_id = $this->identity()->vendorId;
		$this->assertIsInt( $vendor_id );

		return $vendor_id;
	}

	/**
	 * The current identity.
	 */
	private function identity(): \Mercatus\Core\Contracts\Identity\ActorIdentity {
		return self::kernel()->container()->get( CurrentActor::class )->identity();
	}
}
