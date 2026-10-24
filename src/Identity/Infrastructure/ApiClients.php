<?php
/**
 * Registering and managing API clients (SEC-1, IDN-11, IDN-17, IDN-18).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Identity\Infrastructure;

use Mercatus\Core\Contracts\Identity\CurrentActor;
use Mercatus\Core\Identity\Application\ApiClientDirectory;
use Mercatus\Core\Identity\Application\PermissionCatalog;
use Mercatus\Core\Shared\Http\ListRequest;
use Mercatus\Core\Identity\Domain\ApiClient;
use Mercatus\Core\Identity\Domain\IpRanges;
use Mercatus\Core\Shared\Audit\AuditLog;
use Mercatus\Core\Shared\Clock\Clock;
use Mercatus\Core\Shared\Errors\DomainError;
use Mercatus\Core\Shared\Events\EventPublisher;
use Mercatus\Core\Shared\Persistence\TransactionRunner;
use WP_Application_Passwords;

/**
 * The commands behind the REST endpoints and `wp mercatus api-client`: each change, its event and its audit
 * entry in one transaction. Passwords are shown once and never stored by Mercatus.
 */
final class ApiClients implements ApiClientDirectory {

	/**
	 * User meta holding an integration's application ID, suffixed with the client ID.
	 */
	public const APP_ID_META = 'mercatus_api_client_app_';

	/**
	 * Creates the service.
	 *
	 * @param ApiClientStore    $store        Clients.
	 * @param PermissionCatalog $permissions  Known permissions, for scopes.
	 * @param TransactionRunner $transactions Transactions.
	 * @param EventPublisher    $events       Events.
	 * @param AuditLog          $audit        Audit log.
	 * @param CurrentActor      $actors       Who is registering.
	 * @param Clock             $clock        Current time.
	 */
	public function __construct(
		private ApiClientStore $store,
		private PermissionCatalog $permissions,
		private TransactionRunner $transactions,
		private EventPublisher $events,
		private AuditLog $audit,
		private CurrentActor $actors,
		private Clock $clock,
	) {}

	public function page( ListRequest $request ): array {
		return array_map(
			static fn( array $row ): array => array(
				'id'      => $row['id'],
				'version' => $row['version'],
				'client'  => $row['client']->toArray(),
			),
			$this->store->page( $request )
		);
	}

	/**
	 * Registers a client and returns it with its version and application ID, which only this call reveals.
	 *
	 * @param string       $name     Name.
	 * @param int|null     $user_id  The integration's user; null for an app.
	 * @param list<string> $scopes   Scopes.
	 * @param list<string> $ips      IP allow-list.
	 * @param int          $lifetime Password lifetime in days; 0 for no limit.
	 * @return array{array<string, mixed>, int, string}
	 * @throws DomainError When a value isn't allowed (mercatus_api_client_invalid).
	 */
	public function register( string $name, ?int $user_id, array $scopes, array $ips, int $lifetime ): array {
		$name = $this->validate( $name, $scopes, $ips, $lifetime );
		if ( null !== $user_id && false === get_userdata( $user_id ) ) {
			throw self::invalid( 'user_id' );
		}
		$app_id = wp_generate_uuid4();

		$id = $this->transactions->run(
			function () use ( $app_id, $name, $user_id, $scopes, $ips, $lifetime ): int {
				$id = $this->store->insert( self::hash( $app_id ), $name, $user_id, array_values( array_unique( $scopes ) ), $ips, $lifetime, $this->actors->identity()->userId, $this->clock->now() );
				if ( null !== $user_id ) {
					// An integration reuses its application ID for every password it's issued.
					update_user_meta( $user_id, self::APP_ID_META . $id, $app_id );
				}
				$this->events->publish( 'identity.api_client.registered', 'api_client', $id, 1, array( 'client_id' => $id ) );
				$this->audit->record( 'api_client.registered', 'api_client', $id, array( 'scopes' => implode( ',', $scopes ) ) );

				return $id;
			}
		);

		$client = $this->get( $id );

		return array( $client->toArray(), $client->version, $app_id );
	}

	/**
	 * Changes a client that is still at the version the change was based on.
	 *
	 * @param int                  $id       ID.
	 * @param int                  $expected Version from If-Match.
	 * @param array<string, mixed> $changes  New name, scopes, ip_allow_list or password_lifetime_days.
	 * @return array{array<string, mixed>, int}
	 * @throws DomainError When the client is unknown, revoked, changed meanwhile or a value isn't allowed.
	 */
	public function update( int $id, int $expected, array $changes ): array {
		$client = $this->get( $id );
		if ( ! $client->isActive() ) {
			throw DomainError::of( 'mercatus_api_client_revoked', array( 'client_id' => $id ) );
		}
		$name     = is_string( $changes['name'] ?? null ) ? $changes['name'] : $client->name;
		$scopes   = array_key_exists( 'scopes', $changes ) ? self::strings( $changes['scopes'], 'scopes' ) : $client->scopes;
		$ips      = array_key_exists( 'ip_allow_list', $changes ) ? self::strings( $changes['ip_allow_list'], 'ip_allow_list' ) : $client->ipAllowList;
		$lifetime = is_int( $changes['password_lifetime_days'] ?? null ) ? $changes['password_lifetime_days'] : $client->passwordLifetimeDays;
		$name     = $this->validate( $name, $scopes, $ips, $lifetime );

		$this->transactions->run(
			function () use ( $id, $expected, $name, $scopes, $ips, $lifetime, $changes ): void {
				if ( ! $this->store->update( $id, $expected, $name, array_values( array_unique( $scopes ) ), $ips, $lifetime, 'active', $this->clock->now() ) ) {
					throw DomainError::of( 'mercatus_version_conflict', array( 'client_id' => $id ) );
				}
				$this->events->publish( 'identity.api_client.updated', 'api_client', $id, $expected + 1, array( 'client_id' => $id ) );
				$this->audit->record( 'api_client.updated', 'api_client', $id, array( 'fields' => implode( ',', array_keys( $changes ) ) ) );
			}
		);

		return self::serialized( $this->get( $id ) );
	}

	/**
	 * Revokes a client. An integration's passwords are deleted; an app's stop working because the client is
	 * revoked. Repeating it changes nothing.
	 *
	 * @param int $id ID.
	 * @return array{array<string, mixed>, int}
	 */
	public function revoke( int $id ): array {
		$client = $this->get( $id );
		if ( ! $client->isActive() ) {
			return self::serialized( $client );
		}
		$this->transactions->run(
			function () use ( $client ): void {
				if ( ! $this->store->update( $client->id, $client->version, $client->name, $client->scopes, $client->ipAllowList, $client->passwordLifetimeDays, 'revoked', $this->clock->now() ) ) {
					throw DomainError::of( 'mercatus_version_conflict', array( 'client_id' => $client->id ) );
				}
				$this->events->publish( 'identity.api_client.revoked', 'api_client', $client->id, $client->version + 1, array( 'client_id' => $client->id ) );
				$this->audit->record( 'api_client.revoked', 'api_client', $client->id );
			}
		);
		if ( null !== $client->userId ) {
			$app_id = $this->appIdOf( $client );
			foreach ( WP_Application_Passwords::get_user_application_passwords( $client->userId ) as $item ) {
				if ( strtolower( $item['app_id'] ) === strtolower( $app_id ) ) {
					WP_Application_Passwords::delete_application_password( $client->userId, $item['uuid'] );
				}
			}
			delete_user_meta( $client->userId, self::APP_ID_META . $client->id );
		}

		return self::serialized( $this->get( $id ) );
	}

	/**
	 * Creates a password for an integration and returns it, with its UUID; it's never shown again.
	 *
	 * @param int $id ID.
	 * @return array{string, string}
	 * @throws DomainError When the client is unknown, revoked or an app.
	 */
	public function createPassword( int $id ): array {
		$client = $this->get( $id );
		if ( ! $client->isActive() ) {
			throw DomainError::of( 'mercatus_api_client_revoked', array( 'client_id' => $id ) );
		}
		if ( null === $client->userId ) {
			throw DomainError::of( 'mercatus_api_client_not_integration', array( 'client_id' => $id ) );
		}
		$app_id = $this->appIdOf( $client );
		$result = WP_Application_Passwords::create_new_application_password(
			$client->userId,
			array(
				// WordPress refuses a second password with the same name for one user.
				'name'   => $client->name . ' ' . gmdate( 'Y-m-d H:i' ) . ' ' . wp_generate_password( 4, false ),
				'app_id' => $app_id,
			)
		);
		if ( is_wp_error( $result ) ) {
			throw DomainError::of( 'mercatus_api_client_password_failed', array( 'client_id' => $id ) );
		}
		$this->transactions->run(
			function () use ( $client ): void {
				$this->events->publish( 'identity.api_client.password_created', 'api_client', $client->id, $client->version, array( 'client_id' => $client->id ) );
				$this->audit->record( 'api_client.password_created', 'api_client', $client->id );
			}
		);

		return array( $result[0], (string) $result[1]['uuid'] );
	}

	public function appPasswords( int $user_id ): array {
		$items = array();
		foreach ( WP_Application_Passwords::get_user_application_passwords( $user_id ) as $item ) {
			$client  = '' === $item['app_id'] ? null : $this->store->byHash( self::hash( $item['app_id'] ) );
			$items[] = array(
				'uuid'      => $item['uuid'],
				'name'      => $item['name'],
				'client'    => null === $client ? null : $client->name,
				'created'   => gmdate( 'Y-m-d\\TH:i:s\\Z', $item['created'] ),
				'last_used' => null === $item['last_used'] ? null : gmdate( 'Y-m-d\\TH:i:s\\Z', $item['last_used'] ),
			);
		}

		return $items;
	}

	public function revokeAppPassword( int $user_id, string $uuid ): bool {
		return true === WP_Application_Passwords::delete_application_password( $user_id, $uuid );
	}

	/**
	 * A client by ID.
	 *
	 * @param int $id ID.
	 * @throws DomainError When it doesn't exist (mercatus_api_client_unknown).
	 */
	public function get( int $id ): ApiClient {
		return $this->store->byId( $id ) ?? throw DomainError::of( 'mercatus_api_client_unknown', array( 'client_id' => $id ) );
	}

	/**
	 * A client as the API returns it, with its version.
	 *
	 * @param ApiClient $client Client.
	 * @return array{array<string, mixed>, int}
	 */
	private static function serialized( ApiClient $client ): array {
		return array( $client->toArray(), $client->version );
	}

	/**
	 * The lookup key of an application ID.
	 *
	 * @param string $app_id Application ID.
	 */
	public static function hash( string $app_id ): string {
		return hash( 'sha256', strtolower( trim( $app_id ) ) );
	}

	/**
	 * Validates a client's values and returns the trimmed name.
	 *
	 * @param string       $name     Name.
	 * @param list<string> $scopes   Scopes.
	 * @param list<string> $ips      IP allow-list.
	 * @param int          $lifetime Password lifetime in days.
	 * @throws DomainError When a value isn't allowed.
	 */
	private function validate( string $name, array $scopes, array $ips, int $lifetime ): string {
		$name = trim( $name );
		if ( '' === $name || strlen( $name ) > 191 ) {
			throw self::invalid( 'name' );
		}
		if ( array() === $scopes ) {
			throw self::invalid( 'scopes' );
		}
		foreach ( $scopes as $scope ) {
			if ( ! in_array( $scope, array( 'read', 'write' ), true ) && ! $this->permissions->knows( $scope ) ) {
				throw self::invalid( 'scopes' );
			}
		}
		foreach ( $ips as $ip ) {
			if ( ! IpRanges::isValid( $ip ) ) {
				throw self::invalid( 'ip_allow_list' );
			}
		}
		if ( $lifetime < 0 || $lifetime > 3650 ) {
			throw self::invalid( 'password_lifetime_days' );
		}

		return $name;
	}

	/**
	 * A list of strings from input.
	 *
	 * @param mixed  $value Value.
	 * @param string $field Field name, for the error.
	 * @return list<string>
	 * @throws DomainError When it isn't a list of strings.
	 */
	private static function strings( mixed $value, string $field ): array {
		if ( ! is_array( $value ) || array() !== array_filter( $value, static fn( mixed $item ): bool => ! is_string( $item ) ) ) {
			throw self::invalid( $field );
		}

		return array_values( $value );
	}

	/**
	 * The error for a value that isn't allowed.
	 *
	 * @param string $field Field name.
	 */
	private static function invalid( string $field ): DomainError {
		return DomainError::of( 'mercatus_api_client_invalid', array( 'field' => $field ) );
	}

	/**
	 * An integration's application ID, kept in its user's meta at registration.
	 *
	 * @param ApiClient $client Client.
	 */
	private function appIdOf( ApiClient $client ): string {
		$app_id = get_user_meta( (int) $client->userId, self::APP_ID_META . $client->id, true );
		if ( is_string( $app_id ) && '' !== $app_id ) {
			return $app_id;
		}
		throw DomainError::of( 'mercatus_api_client_unknown', array( 'client_id' => $client->id ) );
	}
}
