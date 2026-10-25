<?php
/**
 * The store profile's fields and limits (STO-11).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Store\Domain;

use Mercatus\Core\Shared\Errors\DomainError;

/**
 * Checks a change to a store's profile, visibility and address after the infrastructure has cleaned the text
 * (STO-5): every field has a type and a length limit, and nothing else is accepted (SEC-3).
 */
final class StoreProfile {

	/**
	 * Text fields and their longest length. The description is rich text; the others are plain.
	 */
	public const TEXT = array(
		'tagline'         => 191,
		'description'     => 10000,
		'contact_email'   => 191,
		'contact_phone'   => 40,
		'policy_shipping' => 5000,
		'policy_returns'  => 5000,
		'policy_privacy'  => 5000,
	);

	/**
	 * Address fields and their longest length (DATA-2 fulfilment_locations).
	 */
	public const ADDRESS = array(
		'address_1' => 191,
		'address_2' => 191,
		'city'      => 100,
		'state'     => 100,
		'postcode'  => 20,
		'country'   => 2,
	);

	public const NAME_MAX = 191;

	/**
	 * The fields a change may name.
	 */
	public const FIELDS = array( 'name', 'slug', 'tagline', 'description', 'contact_email', 'contact_phone', 'show_email', 'show_phone', 'policy_shipping', 'policy_returns', 'policy_privacy', 'visibility', 'address' );

	/**
	 * A valid change, with only the fields it names.
	 *
	 * @param array<string, mixed> $changes Cleaned changes.
	 * @return array<string, mixed>
	 * @throws DomainError When a field is unknown or a value doesn't fit (mercatus_store_invalid, naming the field).
	 */
	public static function validate( array $changes ): array {
		$valid = array();
		foreach ( $changes as $field => $value ) {
			$valid[ $field ] = match ( true ) {
				'name' === $field                     => self::text( $field, $value, self::NAME_MAX, true ),
				'slug' === $field                     => is_string( $value ) ? $value : throw self::invalid( $field ),
				isset( self::TEXT[ $field ] )         => self::text( $field, $value, self::TEXT[ $field ], false ),
				in_array( $field, array( 'show_email', 'show_phone' ), true ) => is_bool( $value ) ? $value : throw self::invalid( $field ),
				'visibility' === $field               => is_string( $value ) && in_array( $value, Store::VISIBILITIES, true ) ? $value : throw self::invalid( $field ),
				'address' === $field                  => self::address( $value ),
				default                               => throw self::invalid( (string) $field ),
			};
		}

		return $valid;
	}

	/**
	 * A valid text value.
	 *
	 * @param string $field    Field.
	 * @param mixed  $value    Value.
	 * @param int    $max      Longest length.
	 * @param bool   $required Whether it may be empty.
	 * @throws DomainError When it doesn't fit.
	 */
	private static function text( string $field, mixed $value, int $max, bool $required ): string {
		if ( ! is_string( $value ) || mb_strlen( $value ) > $max || ( $required && '' === trim( $value ) ) ) {
			throw self::invalid( $field );
		}

		return trim( $value );
	}

	/**
	 * A valid address: only its known fields, each within its length; the country is a two-letter code.
	 *
	 * @param mixed $value Address.
	 * @return array<string, string>
	 * @throws DomainError When a field is unknown or doesn't fit.
	 */
	private static function address( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			throw self::invalid( 'address' );
		}
		$address = array();
		foreach ( $value as $field => $text ) {
			if ( ! isset( self::ADDRESS[ $field ] ) ) {
				throw self::invalid( 'address.' . $field );
			}
			$address[ $field ] = self::text( 'address.' . $field, $text, self::ADDRESS[ $field ], false );
		}
		if ( isset( $address['country'] ) && '' !== $address['country'] && 1 !== preg_match( '/^[A-Z]{2}$/', $address['country'] ) ) {
			throw self::invalid( 'address.country' );
		}

		return $address;
	}

	/**
	 * The error for a field that doesn't fit.
	 *
	 * @param string $field Field.
	 */
	private static function invalid( string $field ): DomainError {
		return DomainError::of( 'mercatus_store_invalid', array( 'field' => $field ) );
	}
}
