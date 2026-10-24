<?php
/**
 * The managers endpoints (IDN-15, VEN-16).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Integration\Identity;

use Mercatus\Core\Contracts\Identity\ActorType;
use Mercatus\Core\Contracts\Identity\CurrentActor;
use Mercatus\Core\Identity\Application\PermissionCatalog;
use Mercatus\Core\Identity\Infrastructure\ManagerAppointments;
use Mercatus\Core\Shared\Events\DomainEvent;
use Mercatus\Core\Tests\Integration\Vendor\VendorTestHelpers;
use WP_UnitTestCase;

/**
 * Administrators list, appoint (by user ID or email) and remove managers; repeating either changes nothing,
 * and the user's identity changes at once. Managers themselves can't, whatever they hold (VEN-8).
 */
final class ManagersEndpointTest extends WP_UnitTestCase {

	use VendorTestHelpers;

	/**
	 * Names of events that reached WordPress.
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
		self::grantManagers( array() );
		parent::tear_down();
	}

	public function testAdministratorsListAppointAndRemoveManagers(): void {
		$existing     = $this->userAs( 'marketplace_manager' );
		$customer     = $this->userAs( 'customer' );
		$email        = get_userdata( $customer )->user_email ?? '';
		$this->events = array();

		$this->assertSame( array( $existing ), array_column( $this->data( $this->asAdministrator( 'GET', '/managers' ), 200 )['items'], 'user_id' ) );

		$appointed = $this->data( $this->asAdministrator( 'POST', '/managers', array( 'email' => $email ) ), 200 );
		$this->assertSame( array( $customer, $email ), array( $appointed['user_id'], $appointed['email'] ) );
		$this->data( $this->asAdministrator( 'POST', '/managers', array( 'user_id' => $customer ) ), 200 );
		$this->assertSame( ActorType::MarketplaceManager, $this->identity( $customer ) );

		$this->assertFalse( $this->data( $this->asAdministrator( 'POST', '/managers/' . $customer . '/remove' ), 200 )['manager'] );
		$this->data( $this->asAdministrator( 'POST', '/managers/' . $customer . '/remove' ), 200 );
		$this->assertSame( ActorType::Customer, $this->identity( $customer ) );
		$this->assertSame( array( 'identity.manager.appointed', 'identity.manager.removed' ), $this->events );
	}

	public function testAnUnknownUserIsNotFound(): void {
		$this->assertSame( 'mercatus_user_unknown', $this->data( $this->asAdministrator( 'POST', '/managers', array( 'email' => 'nobody@example.com' ) ), 404 )['code'] );
		$this->assertSame( 'mercatus_user_unknown', $this->data( $this->asAdministrator( 'POST', '/managers/999999/remove' ), 404 )['code'] );
	}

	public function testManagersCannotManageManagers(): void {
		self::grantManagers( array_keys( self::kernel()->container()->get( PermissionCatalog::class )->all() ) );
		wp_set_current_user( $this->userAs( 'marketplace_manager' ) );

		$this->assertSame( 403, rest_do_request( new \WP_REST_Request( 'GET', '/mercatus/v1/managers' ) )->get_status() );
		$this->assertFalse( self::kernel()->container()->get( ManagerAppointments::class )->isManager( $this->userAs( 'customer' ) ) );
	}

	/**
	 * Records an event name.
	 *
	 * @param DomainEvent $event Event.
	 */
	public function onEvent( DomainEvent $event ): void {
		$this->events[] = $event->name;
	}

	/**
	 * A user's actor type, as the next request resolves it.
	 *
	 * @param int $user_id User.
	 */
	private function identity( int $user_id ): ActorType {
		$current = get_current_user_id();
		wp_set_current_user( $user_id );
		$type = self::kernel()->container()->get( CurrentActor::class )->identity()->type;
		wp_set_current_user( $current );

		return $type;
	}
}
