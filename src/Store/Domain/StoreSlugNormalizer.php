<?php
/**
 * Store slugs (STO-9).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Store\Domain;

use Mercatus\Core\Shared\Errors\DomainError;

/**
 * Lower case, ASCII, hyphenated, 3 to 60 characters, never all digits (so `/stores/{slug}` and `/stores/{id}`
 * can't be confused) and never a reserved word. A taken slug gets `-2`, `-3`… on creation.
 */
final class StoreSlugNormalizer {

	public const MIN = 3;

	public const MAX = 60;

	/**
	 * Words that name Mercatus, WordPress or WooCommerce places.
	 */
	public const RESERVED = array( 'admin', 'administrator', 'api', 'cart', 'checkout', 'dashboard', 'edit', 'feed', 'login', 'logout', 'marketplace', 'me', 'my-account', 'new', 'page', 'register', 'search', 'settings', 'shop', 'store', 'stores', 'vendor', 'vendors', 'wp-admin', 'wp-json' );

	private const PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

	/**
	 * A slug made from a store name, ready to be checked for uniqueness.
	 *
	 * @param string       $name     Store name.
	 * @param list<string> $reserved More slugs to avoid, such as the dashboard page's.
	 */
	public static function fromName( string $name, array $reserved = array() ): string {
		$slug = self::normalize( $name );
		if ( strlen( $slug ) < self::MIN || ctype_digit( str_replace( '-', '', $slug ) ) || self::isReserved( $slug, $reserved ) ) {
			$slug = self::normalize( 'store ' . $slug );
		}

		// A name with nothing to keep, such as only symbols, would leave the reserved "store".
		return 'store' === $slug ? 'store-1' : $slug;
	}

	/**
	 * The slug for the nth store wanting the same base: the base itself, then base-2, base-3…
	 *
	 * @param string $base Base slug.
	 * @param int    $n    Attempt, from 1.
	 */
	public static function candidate( string $base, int $n ): string {
		if ( 1 === $n ) {
			return $base;
		}
		$suffix = '-' . $n;

		return rtrim( substr( $base, 0, self::MAX - strlen( $suffix ) ), '-' ) . $suffix;
	}

	/**
	 * Checks a slug a vendor or administrator chose.
	 *
	 * @param string       $slug     Slug.
	 * @param list<string> $reserved More slugs to refuse.
	 * @throws DomainError When it isn't a valid slug (mercatus_store_slug_invalid).
	 */
	public static function assertValid( string $slug, array $reserved = array() ): void {
		$length = strlen( $slug );
		if ( 1 !== preg_match( self::PATTERN, $slug ) || $length < self::MIN || $length > self::MAX || ctype_digit( str_replace( '-', '', $slug ) ) || self::isReserved( $slug, $reserved ) ) {
			throw DomainError::of( 'mercatus_store_slug_invalid', array( 'field' => 'slug' ) );
		}
	}

	/**
	 * Text as a slug: transliterated to ASCII, lower case, runs of other characters as one hyphen, at most MAX
	 * characters.
	 *
	 * @param string $text Text.
	 */
	public static function normalize( string $text ): string {
		$ascii = self::ascii( $text );
		$slug  = trim( (string) preg_replace( '/[^a-z0-9]+/', '-', strtolower( $ascii ) ), '-' );

		return rtrim( substr( $slug, 0, self::MAX ), '-' );
	}

	/**
	 * Whether a slug is reserved.
	 *
	 * @param string       $slug     Slug.
	 * @param list<string> $reserved More reserved slugs.
	 */
	public static function isReserved( string $slug, array $reserved = array() ): bool {
		return in_array( $slug, self::RESERVED, true ) || in_array( $slug, $reserved, true );
	}

	/**
	 * Text in ASCII, through intl when it's there and iconv otherwise; ASCII text as it is, since transliterating is
	 * costly.
	 *
	 * @param string $text Text.
	 */
	private static function ascii( string $text ): string {
		if ( 1 === preg_match( '/^[\x20-\x7E]*$/', $text ) ) {
			return $text;
		}
		if ( function_exists( 'transliterator_transliterate' ) ) {
			$ascii = transliterator_transliterate( 'Any-Latin; Latin-ASCII', $text );
			if ( is_string( $ascii ) ) {
				return $ascii;
			}
		}
		$ascii = function_exists( 'iconv' ) ? @iconv( 'UTF-8', 'ASCII//TRANSLIT//IGNORE', $text ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- iconv warns on characters it drops, which IGNORE asks for.

		return is_string( $ascii ) ? $ascii : $text;
	}
}
