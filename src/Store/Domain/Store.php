<?php
/**
 * A vendor's store (DATA-2, STO-11).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Store\Domain;

/**
 * The profile, the slug, the vendor's visibility choice and a status that follows the vendor (STO-2). The
 * public sees a store only when it's listed: active and public.
 */
final readonly class Store {

	public const PUBLIC = 'public';
	public const HIDDEN = 'hidden';

	public const ACTIVE    = 'active';
	public const SUSPENDED = 'suspended';
	public const CLOSED    = 'closed';

	public const VISIBILITIES = array( self::PUBLIC, self::HIDDEN );

	public const STATUSES = array( self::ACTIVE, self::SUSPENDED, self::CLOSED );

	/**
	 * Creates the store.
	 *
	 * @param int                   $id          ID.
	 * @param int                   $vendorId    Owner vendor.
	 * @param string                $name        Name.
	 * @param string                $slug        Normalized, unique slug (STO-9).
	 * @param array<string, string> $profile     The other text fields, by name (StoreProfile::TEXT).
	 * @param bool                  $showEmail   Whether the contact email is public (STO-6).
	 * @param bool                  $showPhone   Whether the contact phone is public (STO-6).
	 * @param int|null              $logoId      Logo attachment.
	 * @param int|null              $bannerId    Banner attachment.
	 * @param string                $visibility  public or hidden.
	 * @param string                $status      active, suspended or closed.
	 * @param string                $createdAt   When it was created.
	 * @param string                $updatedAt   When it last changed.
	 * @param int                   $version     Version (PLAT-9); a change to the default location bumps it too.
	 */
	public function __construct(
		public int $id,
		public int $vendorId,
		public string $name,
		public string $slug,
		public array $profile,
		public bool $showEmail,
		public bool $showPhone,
		public ?int $logoId,
		public ?int $bannerId,
		public string $visibility,
		public string $status,
		public string $createdAt,
		public string $updatedAt,
		public int $version,
	) {}

	/**
	 * Whether the public sees the store.
	 */
	public function isListed(): bool {
		return self::ACTIVE === $this->status && self::PUBLIC === $this->visibility;
	}

	/**
	 * A profile text field; empty when unset.
	 *
	 * @param string $field One of StoreProfile::TEXT.
	 */
	public function text( string $field ): string {
		return $this->profile[ $field ] ?? '';
	}

	/**
	 * The store as an array, for the cache.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return get_object_vars( $this );
	}

	/**
	 * A store from toArray()'s output.
	 *
	 * @param array<string, mixed> $data Data.
	 */
	public static function fromArray( array $data ): self {
		return new self( ...$data );
	}
}
