<?php
/**
 * The settings endpoints (VEN-16).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Integration\Kernel;

use Mercatus\Core\Identity\Application\IdentitySettings;
use Mercatus\Core\Shared\Events\DomainEvent;
use Mercatus\Core\Tests\Integration\Vendor\VendorTestHelpers;
use Mercatus\Core\Vendor\Application\VendorAccess;
use Mercatus\Core\Vendor\Application\VendorSettings;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * Administrators read each module's settings with what the dashboard needs to draw them, and change them only
 * at the ETag they read; a change is published and audited, and an invalid one changes nothing. Nobody else
 * reads or changes settings, not even a manager holding every permission (VEN-8).
 */
final class SettingsEndpointTest extends WP_UnitTestCase {

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
		wp_set_current_user( $this->userAs( 'administrator' ) );
	}

	public function tear_down(): void {
		remove_action( 'mercatus_event', array( $this, 'onEvent' ) );
		self::kernel()->settings()->set( VendorSettings::AUTO_APPROVE, false );
		self::grantManagers( array() );
		parent::tear_down();
	}

	public function testAdministratorsSeeEachSettingWithWhatItsFieldNeeds(): void {
		$response = $this->call( 'GET', '/settings/core.vendor' );
		$data     = $this->data( $response, 200 );
		$fields   = array_column( $data['settings'], null, 'key' );

		$this->assertSame( 'core.vendor', $data['module'] );
		$this->assertSame( 'Vendors', $data['title'] );
		$this->assertSame( array( 'checkbox', false ), array( $fields[ VendorSettings::AUTO_APPROVE ]['widget'], $fields[ VendorSettings::AUTO_APPROVE ]['value'] ) );
		$this->assertSame( 'page', $fields[ VendorSettings::TERMS_PAGE ]['widget'] );
		$this->assertSame( 'questions', $fields[ VendorSettings::APPLICATION_FIELDS ]['widget'] );
		$this->assertMatchesRegularExpression( '/^"c[0-9a-f]{32}"$/', (string) $response->get_headers()['ETag'] );

		$identity    = array_column( $this->data( $this->call( 'GET', '/settings/core.identity' ), 200 )['settings'], null, 'key' );
		$permissions = $identity[ IdentitySettings::MANAGER_PERMISSIONS ];
		$this->assertSame( 'permissions', $permissions['widget'] );
		$this->assertContains( VendorAccess::MANAGE, array_column( $permissions['options'], 'value' ) );
	}

	public function testAChangeNeedsTheETagItWasBasedOnAndIsPublished(): void {
		$etag   = (string) $this->call( 'GET', '/settings/core.vendor' )->get_headers()['ETag'];
		$values = array( 'values' => array( VendorSettings::AUTO_APPROVE => true ) );

		$this->data( $this->call( 'PATCH', '/settings/core.vendor', $values ), 428 );
		$this->assertSame( 'mercatus_version_conflict', $this->data( $this->call( 'PATCH', '/settings/core.vendor', $values, '"c0"' ), 409 )['code'] );
		$this->assertFalse( self::kernel()->settings()->bool( VendorSettings::AUTO_APPROVE ) );

		$response = $this->call( 'PATCH', '/settings/core.vendor', $values, $etag );
		$fields   = array_column( $this->data( $response, 200 )['settings'], null, 'key' );

		$this->assertTrue( $fields[ VendorSettings::AUTO_APPROVE ]['value'] );
		$this->assertNotSame( $etag, $response->get_headers()['ETag'] );
		$this->assertSame( array( 'kernel.settings.updated' ), $this->events );
		$this->assertSame( array( 'settings.updated' ), $this->auditActions( 'core.vendor' ) );
		$this->data( $this->call( 'PATCH', '/settings/core.vendor', $values, $etag ), 409 );
	}

	public function testChangingTheManagersPermissionsIsPublishedOnItsOwn(): void {
		$etag = (string) $this->call( 'GET', '/settings/core.identity' )->get_headers()['ETag'];

		$this->data( $this->call( 'PATCH', '/settings/core.identity', array( 'values' => array( IdentitySettings::MANAGER_PERMISSIONS => VendorAccess::MANAGE ) ), $etag ), 200 );

		$this->assertSame( array( 'kernel.settings.updated', 'identity.manager.permissions_changed' ), $this->events );
	}

	public function testAnInvalidValueChangesNothing(): void {
		$etag   = (string) $this->call( 'GET', '/settings/core.vendor' )->get_headers()['ETag'];
		$values = array(
			VendorSettings::AUTO_APPROVE => true,
			VendorSettings::TERMS_PAGE   => -1,
		);

		$this->assertSame( 'mercatus_setting_invalid', $this->data( $this->call( 'PATCH', '/settings/core.vendor', array( 'values' => $values ), $etag ), 422 )['code'] );
		$this->assertSame( 'mercatus_setting_unknown', $this->data( $this->call( 'PATCH', '/settings/core.vendor', array( 'values' => array( IdentitySettings::MANAGER_PERMISSIONS => '' ) ), $etag ), 422 )['code'] );
		$this->assertFalse( self::kernel()->settings()->bool( VendorSettings::AUTO_APPROVE ) );
		$this->assertSame( array(), $this->events );
	}

	public function testOnlyAdministratorsReadOrChangeSettings(): void {
		self::grantManagers( array_keys( self::kernel()->container()->get( \Mercatus\Core\Identity\Application\PermissionCatalog::class )->all() ) );

		wp_set_current_user( $this->userAs( 'marketplace_manager' ) );
		$this->data( $this->call( 'GET', '/settings/core.vendor' ), 403 );
		wp_set_current_user( $this->userAs( 'customer' ) );
		$this->data( $this->call( 'GET', '/settings/core.vendor' ), 403 );
		wp_set_current_user( 0 );
		$this->data( $this->call( 'GET', '/settings/core.vendor' ), 401 );
	}

	public function testAModuleThatIsNotActiveIsNotFound(): void {
		$this->assertSame( 'mercatus_module_unknown', $this->data( $this->call( 'GET', '/settings/pro.nothing' ), 404 )['code'] );
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
	 * Calls an endpoint as the current user.
	 *
	 * @param string               $method   Method.
	 * @param string               $route    Route below the namespace.
	 * @param array<string, mixed> $params   Body parameters.
	 * @param string|null          $if_match If-Match header.
	 */
	private function call( string $method, string $route, array $params = array(), ?string $if_match = null ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/mercatus/v1' . $route );
		$request->set_body_params( $params );
		if ( null !== $if_match ) {
			$request->set_header( 'If-Match', $if_match );
		}

		return rest_do_request( $request );
	}

	/**
	 * Audit actions recorded for a module.
	 *
	 * @param string $module_id Module ID.
	 * @return list<string>
	 */
	private function auditActions( string $module_id ): array {
		global $wpdb;
		$actions = $wpdb->get_col( $wpdb->prepare( 'SELECT action FROM %i WHERE resource_id = %s ORDER BY id', $wpdb->prefix . 'mercatus_audit_log', $module_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test assertion.

		return array_values( array_map( 'strval', $actions ) );
	}
}
