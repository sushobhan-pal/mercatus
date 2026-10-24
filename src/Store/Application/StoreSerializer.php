<?php
/**
 * What each audience sees of a store (SEC-2 field-level visibility, STO-6).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Store\Application;

use Mercatus\Core\Store\Domain\Location;
use Mercatus\Core\Store\Domain\Store;

/**
 * The public sees the profile, the contact details the vendor chose to show, and the city, state and country;
 * never the street address, the coordinates or the private flags. The owner and administrators see everything.
 */
final class StoreSerializer {

	/**
	 * The store as everyone sees it.
	 *
	 * @param Store         $store    Store.
	 * @param Location|null $location Its default location.
	 * @return array<string, mixed>
	 */
	public static function public( Store $store, ?Location $location ): array {
		return array(
			'id'          => $store->id,
			'slug'        => $store->slug,
			'name'        => $store->name,
			'tagline'     => $store->text( 'tagline' ),
			'description' => $store->text( 'description' ),
			'logo_id'     => $store->logoId,
			'banner_id'   => $store->bannerId,
			'contact'     => (object) array_filter(
				array(
					'email' => $store->showEmail ? $store->text( 'contact_email' ) : '',
					'phone' => $store->showPhone ? $store->text( 'contact_phone' ) : '',
				)
			),
			'policies'    => self::policies( $store ),
			'location'    => array(
				'city'    => $location->address['city'] ?? '',
				'state'   => $location->address['state'] ?? '',
				'country' => $location->address['country'] ?? '',
			),
			'created_at'  => self::iso( $store->createdAt ),
		);
	}

	/**
	 * The store as its owner and administrators see it.
	 *
	 * @param Store         $store    Store.
	 * @param Location|null $location Its default location.
	 * @return array<string, mixed>
	 */
	public static function full( Store $store, ?Location $location ): array {
		return array(
			'id'            => $store->id,
			'vendor_id'     => $store->vendorId,
			'slug'          => $store->slug,
			'name'          => $store->name,
			'tagline'       => $store->text( 'tagline' ),
			'description'   => $store->text( 'description' ),
			'logo_id'       => $store->logoId,
			'banner_id'     => $store->bannerId,
			'contact_email' => $store->text( 'contact_email' ),
			'contact_phone' => $store->text( 'contact_phone' ),
			'show_email'    => $store->showEmail,
			'show_phone'    => $store->showPhone,
			'policies'      => self::policies( $store ),
			'visibility'    => $store->visibility,
			'status'        => $store->status,
			'listed'        => $store->isListed(),
			'address'       => (object) ( $location->address ?? array() ),
			'coordinates'   => null === $location || null === $location->latitude || null === $location->longitude ? null : array(
				'latitude'  => $location->latitude,
				'longitude' => $location->longitude,
				'geo_cell'  => $location->geoCell,
			),
			'created_at'    => self::iso( $store->createdAt ),
			'updated_at'    => self::iso( $store->updatedAt ),
			'version'       => $store->version,
		);
	}

	/**
	 * What /me says about the actor's store (IDN-12, STO-13).
	 *
	 * @param Store $store Store.
	 * @return array{id: int, name: string, slug: string, visibility: string, status: string, listed: bool}
	 */
	public static function summary( Store $store ): array {
		return array(
			'id'         => $store->id,
			'name'       => $store->name,
			'slug'       => $store->slug,
			'visibility' => $store->visibility,
			'status'     => $store->status,
			'listed'     => $store->isListed(),
		);
	}

	/**
	 * The three policies.
	 *
	 * @param Store $store Store.
	 * @return array{shipping: string, returns: string, privacy: string}
	 */
	private static function policies( Store $store ): array {
		return array(
			'shipping' => $store->text( 'policy_shipping' ),
			'returns'  => $store->text( 'policy_returns' ),
			'privacy'  => $store->text( 'policy_privacy' ),
		);
	}

	/**
	 * A stored UTC time in ISO 8601.
	 *
	 * @param string $time Database time.
	 */
	private static function iso( string $time ): string {
		return str_replace( ' ', 'T', $time ) . 'Z';
	}
}
