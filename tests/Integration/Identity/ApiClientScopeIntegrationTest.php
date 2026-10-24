<?php
/**
 * Application passwords work only through a registered client and within its scopes (TEST-3 row 4, SEC-1, IDN-11, IDN-17, TM-2).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Integration\Identity;

use Mercatus\Core\Identity\Infrastructure\ApiClientAuthentication;
use Mercatus\Core\Identity\Infrastructure\ApiClients;
use Mercatus\Core\Identity\Infrastructure\RequestSubject;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * WordPress authenticates the password; Mercatus refuses unregistered, revoked or expired ones and requests
 * from outside the allow-list, and caps what accepted ones may do by the client's scopes.
 */
final class ApiClientScopeIntegrationTest extends WP_UnitTestCase {

	use IdentityTestActors;

	private string $address = '';

	public function set_up(): void {
		parent::set_up();
		$this->address = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	}

	public function tear_down(): void {
		self::kernel()->container()->get( ApiClientAuthentication::class )->reset();
		// WordPress's own listener records the password here, and then skips the cookie nonce check.
		unset( $GLOBALS['wp_rest_application_password_uuid'], $GLOBALS['wp_rest_application_password_status'] );
		$_SERVER['REMOTE_ADDR'] = $this->address;
		parent::tear_down();
	}

	public function testAPasswordForAnUnregisteredAppIsRefused(): void {
		$this->signIn( $this->userAs( 'customer' ), wp_generate_uuid4() );

		$this->assertRefused( 401, 'mercatus_api_client_unregistered', $this->get( '/me' ) );
	}

	public function testAReadOnlyClientCanReadButNotWrite(): void {
		list( , $app_id ) = $this->register( null, array( 'read' ) );
		$this->signIn( $this->userAs( 'customer' ), $app_id );

		$this->assertSame( 200, $this->get( '/me' )->get_status() );
		$this->assertRefused( 403, 'mercatus_api_client_scope', rest_do_request( new WP_REST_Request( 'POST', '/mercatus/v1/me/app-passwords/' . wp_generate_uuid4() . '/revoke' ) ) );
	}

	public function testARevokedClientsPasswordsStopWorking(): void {
		list( $id, $app_id ) = $this->register( null, array( 'read' ) );
		self::kernel()->container()->get( ApiClients::class )->revoke( $id );
		$this->signIn( $this->userAs( 'customer' ), $app_id );

		$this->assertRefused( 401, 'mercatus_api_client_revoked', $this->get( '/me' ) );
	}

	public function testAPasswordOlderThanTheClientsLifetimeIsRefused(): void {
		list( , $app_id ) = $this->register( null, array( 'read' ), array(), 1 );
		$this->signIn( $this->userAs( 'customer' ), $app_id, time() - 2 * DAY_IN_SECONDS );

		$this->assertRefused( 401, 'mercatus_api_client_expired', $this->get( '/me' ) );
	}

	public function testOnlyAllowedAddressesMayUseTheClient(): void {
		list( , $app_id ) = $this->register( null, array( 'read' ), array( '10.0.0.0/8' ) );
		$user_id          = $this->userAs( 'customer' );

		$_SERVER['REMOTE_ADDR'] = '198.51.100.4';
		$this->signIn( $user_id, $app_id );
		$this->assertRefused( 403, 'mercatus_api_client_ip_denied', $this->get( '/me' ) );

		$_SERVER['REMOTE_ADDR'] = '10.1.2.3';
		$this->signIn( $user_id, $app_id );
		$this->assertSame( 200, $this->get( '/me' )->get_status() );
	}

	public function testScopesCapEvenAnAdministratorsPermissions(): void {
		$admin            = $this->userAs( 'administrator' );
		list( , $narrow ) = $this->register( $admin, array( 'read' ) );
		list( , $health ) = $this->register( $admin, array( 'read', 'kernel.health.view' ) );

		$this->signIn( $admin, $narrow );
		$this->assertRefused( 403, 'mercatus_api_client_scope', $this->get( '/health' ) );

		$this->signIn( $admin, $health );
		$this->assertSame( 200, $this->get( '/health' )->get_status() );
	}

	public function testAnIntegrationsIdOnAnotherUsersPasswordIsRefused(): void {
		list( , $app_id ) = $this->register( $this->userAs( 'customer' ), array( 'read' ) );
		$this->signIn( $this->userAs( 'customer' ), $app_id );

		$this->assertRefused( 401, 'mercatus_api_client_unregistered', $this->get( '/me' ) );
	}

	public function testRequestsCountAgainstTheClient(): void {
		list( $id, $app_id ) = $this->register( null, array( 'read' ) );
		$this->signIn( $this->userAs( 'customer' ), $app_id );

		$this->assertSame( 'client:' . $id, self::kernel()->container()->get( RequestSubject::class )->key() );
	}

	/**
	 * Registers a client as an administrator would.
	 *
	 * @param int|null     $user_id  The integration's user; null for an app.
	 * @param list<string> $scopes   Scopes.
	 * @param list<string> $ips      IP allow-list.
	 * @param int          $lifetime Password lifetime in days.
	 * @return array{int, string} ID and application ID.
	 */
	private function register( ?int $user_id, array $scopes, array $ips = array(), int $lifetime = 0 ): array {
		list( $client, , $app_id ) = self::kernel()->container()->get( ApiClients::class )->register( 'Probe ' . wp_generate_password( 6, false ), $user_id, $scopes, $ips, $lifetime );

		return array( (int) $client['id'], $app_id );
	}

	/**
	 * Signs in as WordPress does after checking an application password.
	 *
	 * @param int    $user_id User.
	 * @param string $app_id  The password's application ID.
	 * @param int    $created When the password was created.
	 */
	private function signIn( int $user_id, string $app_id, int $created = 0 ): void {
		wp_set_current_user( $user_id );
		do_action(
			'application_password_did_authenticate', // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- simulates WordPress's own action.
			get_userdata( $user_id ),
			array(
				'uuid'    => wp_generate_uuid4(),
				'app_id'  => $app_id,
				'name'    => 'Probe',
				'created' => 0 === $created ? time() : $created,
			)
		);
	}

	/**
	 * A GET request to a Mercatus route.
	 *
	 * @param string $route Route below the namespace.
	 */
	private function get( string $route ): WP_REST_Response {
		return rest_do_request( new WP_REST_Request( 'GET', '/mercatus/v1' . $route ) );
	}

	/**
	 * Asserts a refusal's status and code.
	 *
	 * @param int              $status   Status.
	 * @param string           $code     Code.
	 * @param WP_REST_Response $response Response.
	 */
	private function assertRefused( int $status, string $code, WP_REST_Response $response ): void {
		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertSame( array( $status, $code ), array( $response->get_status(), $data['code'] ?? null ) );
	}
}
