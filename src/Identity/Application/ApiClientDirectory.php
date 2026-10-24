<?php
/**
 * API clients, as the endpoints see them (IDN-18).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Identity\Application;

use Mercatus\Core\Shared\Http\ListRequest;

/**
 * Infrastructure implements it over the clients table and WordPress's application passwords. Clients come
 * back serialized for the API, with their version for ETags (PLAT-9). Failures are domain errors.
 */
interface ApiClientDirectory {

	/**
	 * One page of clients, with one extra row to tell whether more follow.
	 *
	 * @param ListRequest $request Validated list request.
	 * @return list<array{id: int, version: int, client: array<string, mixed>}>
	 */
	public function page( ListRequest $request ): array;

	/**
	 * Registers a client.
	 *
	 * @param string       $name     Name.
	 * @param int|null     $user_id  The integration's user; null for an app.
	 * @param list<string> $scopes   Scopes.
	 * @param list<string> $ips      IP allow-list.
	 * @param int          $lifetime Password lifetime in days; 0 for no limit.
	 * @return array{array<string, mixed>, int, string} The client, its version and its application ID.
	 */
	public function register( string $name, ?int $user_id, array $scopes, array $ips, int $lifetime ): array;

	/**
	 * Changes a client still at the expected version.
	 *
	 * @param int                  $id       ID.
	 * @param int                  $expected Version from If-Match.
	 * @param array<string, mixed> $changes  New name, scopes, ip_allow_list or password_lifetime_days.
	 * @return array{array<string, mixed>, int}
	 */
	public function update( int $id, int $expected, array $changes ): array;

	/**
	 * Revokes a client.
	 *
	 * @param int $id ID.
	 * @return array{array<string, mixed>, int}
	 */
	public function revoke( int $id ): array;

	/**
	 * Creates an integration's password.
	 *
	 * @param int $id ID.
	 * @return array{string, string} The password and its UUID.
	 */
	public function createPassword( int $id ): array;

	/**
	 * A user's app sign-ins.
	 *
	 * @param int $user_id User.
	 * @return list<array<string, mixed>>
	 */
	public function appPasswords( int $user_id ): array;

	/**
	 * Deletes one of a user's app sign-ins; false when there's no such sign-in.
	 *
	 * @param int    $user_id User.
	 * @param string $uuid    Password UUID.
	 */
	public function revokeAppPassword( int $user_id, string $uuid ): bool;
}
