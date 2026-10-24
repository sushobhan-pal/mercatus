<?php
/**
 * A page of a cursor-paginated list (API-1, PLAT-9).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Shared\Http;

use Closure;
use WP_REST_Response;

/**
 * Built from per_page + 1 fetched rows, so has_more needs no COUNT query. The ETag hashes the page's IDs and
 * versions.
 */
final class Page {

	/**
	 * The response for fetched rows.
	 *
	 * @param list<array<string, mixed>>                $rows      Rows in order, up to per_page + 1, each with id, version and the sort column.
	 * @param ListRequest                               $page      The request.
	 * @param Closure(array<string, mixed>): array<mixed> $serialize Turns a row into an item.
	 */
	public static function response( array $rows, ListRequest $page, Closure $serialize ): WP_REST_Response {
		$has_more = count( $rows ) > $page->perPage;
		$rows     = array_slice( $rows, 0, $page->perPage );
		$last     = end( $rows );
		$next     = null;
		if ( $has_more && is_array( $last ) ) {
			$value = $last[ $page->sort ] ?? null;
			$next  = Cursor::encode( $page->sort, $page->direction, is_int( $value ) || is_string( $value ) ? $value : '', self::int( $last['id'] ?? null ) );
		}

		$response = new WP_REST_Response(
			array(
				'items'       => array_map( $serialize, $rows ),
				'per_page'    => $page->perPage,
				'next_cursor' => $next,
				'has_more'    => $has_more,
			)
		);
		$response->header( 'ETag', ETag::forList( array_map( static fn( array $row ): array => array( self::int( $row['id'] ?? null ), self::int( $row['version'] ?? null ) ), $rows ) ) );

		return $response;
	}

	/**
	 * A database value as an integer.
	 *
	 * @param mixed $value Value.
	 */
	private static function int( mixed $value ): int {
		return is_numeric( $value ) ? (int) $value : 0;
	}
}
