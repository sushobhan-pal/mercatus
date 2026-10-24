<?php
/**
 * Requests authenticated with an application password (SEC-1, IDN-11, IDN-17, TM-2).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Identity\Infrastructure;

use Mercatus\Core\Contracts\Identity\ActorIdentity;
use Mercatus\Core\Identity\Domain\ApiClient;
use Mercatus\Core\Identity\Domain\IpRanges;
use Mercatus\Core\Shared\Clock\Clock;
use WP_User;

/**
 * WordPress authenticates the password and tells Mercatus which one it was. Mercatus accepts it only when its
 * application ID belongs to a registered, active client, the password is within the client's lifetime and the
 * request comes from an allowed address. The client's scopes then cap the user's permissions. Cookie
 * requests have no client.
 */
final class ApiClientAuthentication {

	/**
	 * The password item WordPress authenticated, and its user.
	 *
	 * @var array{user_id: int, app_id: string, created: int}|null
	 */
	private ?array $password = null;

	private bool $checked = false;

	private ?ApiClient $client = null;

	private ?string $problem = null;

	/**
	 * Creates the service.
	 *
	 * @param ApiClientStore $store   Clients.
	 * @param ClientAddress  $address The request's address.
	 * @param Clock          $clock   Current time.
	 */
	public function __construct( private ApiClientStore $store, private ClientAddress $address, private Clock $clock ) {}

	/**
	 * Records the password WordPress authenticated; hooked to `application_password_did_authenticate`.
	 *
	 * @param WP_User      $user User.
	 * @param array<mixed> $item Password item.
	 */
	public function authenticated( WP_User $user, array $item ): void {
		$this->reset();
		$this->password = array(
			'user_id' => $user->ID,
			'app_id'  => is_string( $item['app_id'] ?? null ) ? $item['app_id'] : '',
			'created' => is_numeric( $item['created'] ?? null ) ? (int) $item['created'] : 0,
		);
	}

	/**
	 * Forgets the request's password, for the next request in a long-running process.
	 */
	public function reset(): void {
		$this->password = null;
		$this->checked  = false;
		$this->client   = null;
		$this->problem  = null;
	}

	/**
	 * Why the request's password is refused: mercatus_api_client_unregistered, _revoked or _expired (401), or
	 * _ip_denied (403); null when it's accepted or there's none.
	 */
	public function problem(): ?string {
		$this->check();

		return $this->problem;
	}

	/**
	 * The identity as the client may use it: capped by its scopes, or anonymous when the password is refused.
	 *
	 * @param ActorIdentity $identity The user's identity.
	 */
	public function apply( ActorIdentity $identity ): ActorIdentity {
		$password = $this->password;
		if ( null === $password ) {
			return $identity;
		}
		$this->check();
		if ( null === $this->client || $identity->userId !== $password['user_id'] ) {
			return ActorIdentity::anonymous();
		}

		return new ActorIdentity( $identity->type, $identity->userId, $identity->vendorId, $identity->permissions, $identity->storePermissions, $identity->managerScope, $this->client->id, $this->client->scopes );
	}

	/**
	 * Checks the password against its client, once per request.
	 */
	private function check(): void {
		if ( $this->checked || null === $this->password ) {
			return;
		}
		$this->checked = true;
		$client        = '' === $this->password['app_id'] ? null : $this->store->byHash( ApiClients::hash( $this->password['app_id'] ) );
		$now           = $this->clock->now();
		if ( null === $client || ( null !== $client->userId && $client->userId !== $this->password['user_id'] ) ) {
			$this->problem = 'mercatus_api_client_unregistered';
		} elseif ( ! $client->isActive() ) {
			$this->problem = 'mercatus_api_client_revoked';
		} elseif ( $client->passwordExpired( $this->password['created'], $now->getTimestamp() ) ) {
			$this->problem = 'mercatus_api_client_expired';
		} elseif ( ! IpRanges::allows( $this->address->current(), $client->ipAllowList ) ) {
			$this->problem = 'mercatus_api_client_ip_denied';
		} else {
			$this->client = $client;
			$this->store->touch( $client->id, $now );
		}
	}
}
