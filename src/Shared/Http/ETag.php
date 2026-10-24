<?php
/**
 * ETags (PLAT-9, IDN-8).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Shared\Http;

/**
 * An item's ETag is its database version; a list's hashes the page's IDs and versions; a response without a
 * version, such as /me, hashes its content.
 */
final class ETag {

	/**
	 * The ETag of an item.
	 *
	 * @param int $version The item's version column.
	 */
	public static function forVersion( int $version ): string {
		return '"v' . $version . '"';
	}

	/**
	 * The ETag of a page of items.
	 *
	 * @param list<array{int, int}> $items ID and version of each item, in page order.
	 */
	public static function forList( array $items ): string {
		$pairs = array_map( static fn( array $item ): string => $item[0] . ':' . $item[1], $items );

		return '"l' . substr( hash( 'sha256', implode( ',', $pairs ) ), 0, 32 ) . '"';
	}

	/**
	 * The ETag of content that has no version.
	 *
	 * @param mixed $data Response data.
	 */
	public static function forContent( mixed $data ): string {
		return '"c' . substr( hash( 'sha256', (string) json_encode( $data ) ), 0, 32 ) . '"'; // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- hashed, never output; runs without WordPress in unit tests.
	}

	/**
	 * Whether an If-None-Match or If-Match header names the ETag. Weak comparison, as If-None-Match uses.
	 *
	 * @param string|null $header Header value.
	 * @param string      $etag   ETag.
	 */
	public static function matches( ?string $header, string $etag ): bool {
		if ( null === $header || '' === trim( $header ) ) {
			return false;
		}
		foreach ( explode( ',', $header ) as $candidate ) {
			$candidate = trim( $candidate );
			if ( '*' === $candidate || ( str_starts_with( $candidate, 'W/' ) ? substr( $candidate, 2 ) : $candidate ) === $etag ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The version an If-Match header names, or null when it names none.
	 *
	 * @param string|null $header Header value.
	 */
	public static function version( ?string $header ): ?int {
		return null !== $header && 1 === preg_match( '/^"v(\d+)"$/', trim( $header ), $match ) ? (int) $match[1] : null;
	}
}
