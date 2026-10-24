<?php
/**
 * Who is acting (SEC-1, IDN-1).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Contracts\Identity;

use Mercatus\Core\Shared\Actor\ActorRef;

/**
 * Immutable, resolved once per request and cached across requests (IDN-2). The type is the highest that
 * applies; store membership, permissions and a manager's vendor scope are kept apart from it.
 */
final readonly class ActorIdentity {

	/**
	 * Creates the identity.
	 *
	 * @param ActorType         $type             SEC-1 type.
	 * @param int               $userId           WordPress user; 0 when signed out.
	 * @param int|null          $vendorId         The vendor (tenant) the user owns or staffs.
	 * @param list<string>      $permissions      Named permissions across the marketplace; administrators hold all.
	 * @param list<string>|null $storePermissions Permissions inside the store; null for all of them (owners).
	 * @param VendorScope|null  $managerScope     Vendors a manager may act on (IDN-15).
	 * @param int|null          $apiClientId      The API client the request came through.
	 */
	public function __construct(
		public ActorType $type,
		public int $userId = 0,
		public ?int $vendorId = null,
		public array $permissions = array(),
		public ?array $storePermissions = null,
		public ?VendorScope $managerScope = null,
		public ?int $apiClientId = null,
	) {}

	/**
	 * Nobody signed in.
	 */
	public static function anonymous(): self {
		return new self( ActorType::Anonymous );
	}

	/**
	 * Whether a user is signed in.
	 */
	public function isSignedIn(): bool {
		return $this->userId > 0;
	}

	/**
	 * Whether the actor is a marketplace administrator.
	 */
	public function isAdministrator(): bool {
		return ActorType::Administrator === $this->type;
	}

	/**
	 * Whether the actor holds a named permission across the marketplace.
	 *
	 * @param string $permission Permission name.
	 */
	public function holds( string $permission ): bool {
		return $this->isAdministrator() || in_array( $permission, $this->permissions, true );
	}

	/**
	 * Whether the actor may use a permission inside its own store.
	 *
	 * @param string $permission Permission name.
	 */
	public function holdsInStore( string $permission ): bool {
		return null !== $this->vendorId && ( null === $this->storePermissions || in_array( $permission, $this->storePermissions, true ) );
	}

	/**
	 * Whether the actor may act on a vendor's resources as an administrator or a manager in scope.
	 *
	 * @param int $vendor_id Vendor ID.
	 */
	public function managesVendor( int $vendor_id ): bool {
		return $this->isAdministrator()
			|| ( ActorType::MarketplaceManager === $this->type && null !== $this->managerScope && $this->managerScope->includes( $vendor_id ) );
	}

	/**
	 * The reference events and the audit log record.
	 */
	public function ref(): ActorRef {
		return $this->isSignedIn() ? new ActorRef( $this->type->value, $this->userId ) : ActorRef::anonymous();
	}

	/**
	 * The identity as plain data, for the cache.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'type'              => $this->type->value,
			'user_id'           => $this->userId,
			'vendor_id'         => $this->vendorId,
			'permissions'       => $this->permissions,
			'store_permissions' => $this->storePermissions,
			'manager_scope'     => null === $this->managerScope ? null : array( 'vendor_ids' => $this->managerScope->vendorIds ),
			'api_client_id'     => $this->apiClientId,
		);
	}

	/**
	 * An identity from toArray()'s data, or null when the data is malformed.
	 *
	 * @param array<mixed> $data Data.
	 */
	public static function fromArray( array $data ): ?self {
		$type = is_string( $data['type'] ?? null ) ? ActorType::tryFrom( $data['type'] ) : null;
		if ( null === $type || ! is_int( $data['user_id'] ?? null ) || ! self::isNames( $data['permissions'] ?? null ) ) {
			return null;
		}
		$store = $data['store_permissions'] ?? null;
		$scope = $data['manager_scope'] ?? null;
		$ids   = is_array( $scope ) ? ( $scope['vendor_ids'] ?? null ) : null;
		if ( ( null !== $store && ! self::isNames( $store ) ) || ( null !== $scope && ! is_array( $scope ) ) || ( null !== $ids && ! self::isIds( $ids ) ) ) {
			return null;
		}

		return new self(
			$type,
			$data['user_id'],
			is_int( $data['vendor_id'] ?? null ) ? $data['vendor_id'] : null,
			$data['permissions'],
			$store,
			null === $scope ? null : ( null === $ids ? VendorScope::all() : VendorScope::only( $ids ) ),
			is_int( $data['api_client_id'] ?? null ) ? $data['api_client_id'] : null
		);
	}

	/**
	 * Whether a value is a list of permission names.
	 *
	 * @param mixed $value Value.
	 * @phpstan-assert-if-true list<string> $value
	 */
	private static function isNames( mixed $value ): bool {
		return is_array( $value ) && array_is_list( $value ) && array() === array_filter( $value, static fn( mixed $name ): bool => ! is_string( $name ) );
	}

	/**
	 * Whether a value is a list of IDs.
	 *
	 * @param mixed $value Value.
	 * @phpstan-assert-if-true list<int> $value
	 */
	private static function isIds( mixed $value ): bool {
		return is_array( $value ) && array_is_list( $value ) && array() === array_filter( $value, static fn( mixed $id ): bool => ! is_int( $id ) );
	}
}
