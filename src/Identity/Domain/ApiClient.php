<?php
/**
 * A registered API client (SEC-1, IDN-11, IDN-17).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Identity\Domain;

/**
 * An integration acts as one user; an app is any user who signs in through WordPress's authorize-application
 * flow with the app's ID. Scopes cap the user's own permissions.
 */
final readonly class ApiClient {

	/**
	 * Creates the client.
	 *
	 * @param int          $id                   ID.
	 * @param string       $name                 Name.
	 * @param int|null     $userId               The integration's user; null for an app.
	 * @param list<string> $scopes               read, write and permission names.
	 * @param list<string> $ipAllowList          Addresses and CIDR ranges; empty for any.
	 * @param int          $passwordLifetimeDays Days a password works after it's created; 0 for no limit.
	 * @param string       $status               active or revoked.
	 * @param string|null  $lastUsedAt           When a request last came through it.
	 * @param string       $createdAt            When it was registered.
	 * @param string       $updatedAt            When it last changed.
	 * @param int          $version              Version, for ETags and If-Match (PLAT-9).
	 */
	public function __construct(
		public int $id,
		public string $name,
		public ?int $userId,
		public array $scopes,
		public array $ipAllowList,
		public int $passwordLifetimeDays,
		public string $status,
		public ?string $lastUsedAt,
		public string $createdAt,
		public string $updatedAt,
		public int $version,
	) {}

	/**
	 * integration or app.
	 */
	public function kind(): string {
		return null === $this->userId ? 'app' : 'integration';
	}

	/**
	 * Whether requests may come through it.
	 */
	public function isActive(): bool {
		return 'active' === $this->status;
	}

	/**
	 * Whether a password created at a time has outlived the client's password lifetime.
	 *
	 * @param int $created Unix time the password was created.
	 * @param int $now     Unix time now.
	 */
	public function passwordExpired( int $created, int $now ): bool {
		return $this->passwordLifetimeDays > 0 && $created + $this->passwordLifetimeDays * DAY_IN_SECONDS < $now;
	}

	/**
	 * The client as the API returns it.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'id'                     => $this->id,
			'name'                   => $this->name,
			'kind'                   => $this->kind(),
			'user_id'                => $this->userId,
			'scopes'                 => $this->scopes,
			'ip_allow_list'          => $this->ipAllowList,
			'password_lifetime_days' => $this->passwordLifetimeDays,
			'status'                 => $this->status,
			'last_used_at'           => null === $this->lastUsedAt ? null : self::iso( $this->lastUsedAt ),
			'created_at'             => self::iso( $this->createdAt ),
			'updated_at'             => self::iso( $this->updatedAt ),
			'version'                => $this->version,
		);
	}

	/**
	 * A stored UTC time in ISO 8601.
	 *
	 * @param string $time Stored time.
	 */
	private static function iso( string $time ): string {
		return str_replace( ' ', 'T', $time ) . 'Z';
	}
}
