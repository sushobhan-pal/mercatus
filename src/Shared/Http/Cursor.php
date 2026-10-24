<?php
/**
 * Opaque cursors for keyset pagination (API-1, IDN-7).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Shared\Http;

/**
 * Base64url JSON of the last item's sort value and ID, with the sort it belongs to. Not signed: tampering can
 * only move within rows the actor may already see, and every value is checked against the list's allow-list
 * and bound with $wpdb->prepare() (SEC-3).
 */
final class Cursor {

	/**
	 * A cursor after an item.
	 *
	 * @param string     $sort      Sort column.
	 * @param string     $direction asc or desc.
	 * @param int|string $value     The item's sort value.
	 * @param int        $id        The item's ID.
	 */
	public static function encode( string $sort, string $direction, int|string $value, int $id ): string {
		$json = (string) json_encode( array( $sort, $direction, $value, $id ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- runs without WordPress in unit tests.

		return rtrim( strtr( base64_encode( $json ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- an opaque URL-safe cursor.
	}

	/**
	 * A cursor's sort, direction, value and ID, or null when it isn't one.
	 *
	 * @param string $cursor Cursor.
	 * @return array{string, string, int|string, int}|null
	 */
	public static function decode( string $cursor ): ?array {
		if ( strlen( $cursor ) > 512 || 1 !== preg_match( '/^[A-Za-z0-9_-]+$/', $cursor ) ) {
			return null;
		}
		$data = json_decode( (string) base64_decode( strtr( $cursor, '-_', '+/' ), true ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- reads the cursor back; every value is then checked.
		if ( ! is_array( $data ) || 4 !== count( $data ) || ! array_is_list( $data ) ) {
			return null;
		}
		list( $sort, $direction, $value, $id ) = $data;
		if ( ! is_string( $sort ) || ! in_array( $direction, array( 'asc', 'desc' ), true ) || ! ( is_int( $value ) || is_string( $value ) ) || ! is_int( $id ) ) {
			return null;
		}

		return array( $sort, $direction, $value, $id );
	}
}
