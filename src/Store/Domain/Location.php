<?php
/**
 * A fulfilment location (DATA-2, CAT-1, CAT-6).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Store\Domain;

/**
 * In Free, each vendor has one: the default location, created with the store (STO-4). Its coordinates and
 * `geo_cell` are empty until a geocoder fills them (STO-14).
 */
final readonly class Location {

	public const DEFAULT = 'default';

	/**
	 * Creates the location.
	 *
	 * @param int                   $id        ID.
	 * @param int                   $vendorId  Owner vendor.
	 * @param string                $type      default (Pro adds the others).
	 * @param bool                  $isDefault Whether it's the vendor's default location.
	 * @param string                $status    active.
	 * @param array<string, string> $address   Address fields (StoreProfile::ADDRESS).
	 * @param float|null            $latitude  Latitude.
	 * @param float|null            $longitude Longitude.
	 * @param string|null           $geoCell   6-character geohash prefix (CAT-6).
	 * @param int                   $version   Version.
	 */
	public function __construct(
		public int $id,
		public int $vendorId,
		public string $type,
		public bool $isDefault,
		public string $status,
		public array $address,
		public ?float $latitude,
		public ?float $longitude,
		public ?string $geoCell,
		public int $version,
	) {}

	/**
	 * The location as an array, for the cache.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return get_object_vars( $this );
	}

	/**
	 * A location from toArray()'s output.
	 *
	 * @param array<string, mixed> $data Data.
	 */
	public static function fromArray( array $data ): self {
		return new self( ...$data );
	}
}
