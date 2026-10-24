<?php
/**
 * IP allow-lists (SEC-1, IDN-11).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Identity\Domain;

/**
 * Entries are single addresses or CIDR ranges, IPv4 or IPv6.
 */
final class IpRanges {

	/**
	 * Whether an entry is an address or a CIDR range.
	 *
	 * @param string $entry Entry.
	 */
	public static function isValid( string $entry ): bool {
		return null !== self::parse( $entry );
	}

	/**
	 * Whether an address is in any of the ranges. An empty list allows every address.
	 *
	 * @param string       $address IP address.
	 * @param list<string> $ranges  Entries.
	 */
	public static function allows( string $address, array $ranges ): bool {
		if ( array() === $ranges ) {
			return true;
		}
		$packed = @inet_pton( $address ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- an invalid address simply matches nothing.
		if ( false === $packed ) {
			return false;
		}
		foreach ( $ranges as $range ) {
			$parsed = self::parse( $range );
			if ( null !== $parsed && strlen( $parsed[0] ) === strlen( $packed ) && self::prefixMatches( $packed, $parsed[0], $parsed[1] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * An entry's packed network address and prefix length.
	 *
	 * @param string $entry Entry.
	 * @return array{string, int}|null
	 */
	private static function parse( string $entry ): ?array {
		$parts  = explode( '/', trim( $entry ), 2 );
		$packed = @inet_pton( $parts[0] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- validation.
		if ( false === $packed ) {
			return null;
		}
		$bits = strlen( $packed ) * 8;
		if ( ! isset( $parts[1] ) ) {
			return array( $packed, $bits );
		}
		if ( ! ctype_digit( $parts[1] ) || (int) $parts[1] > $bits ) {
			return null;
		}

		return array( $packed, (int) $parts[1] );
	}

	/**
	 * Whether two packed addresses share their first $prefix bits.
	 *
	 * @param string $a      Packed address.
	 * @param string $b      Packed address.
	 * @param int    $prefix Prefix length.
	 */
	private static function prefixMatches( string $a, string $b, int $prefix ): bool {
		$bytes = intdiv( $prefix, 8 );
		if ( substr( $a, 0, $bytes ) !== substr( $b, 0, $bytes ) ) {
			return false;
		}
		$rest = $prefix % 8;
		if ( 0 === $rest ) {
			return true;
		}
		$mask = ( 0xFF << ( 8 - $rest ) ) & 0xFF;

		return ( ord( $a[ $bytes ] ) & $mask ) === ( ord( $b[ $bytes ] ) & $mask );
	}
}
