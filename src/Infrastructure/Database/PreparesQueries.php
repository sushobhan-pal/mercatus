<?php
/**
 * Prepared statements that fail loudly (SEC-3).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Infrastructure\Database;

use RuntimeException;
use wpdb;

/**
 * For repositories over $wpdb: every query with values goes through prepare(), and a query WordPress can't
 * prepare throws instead of running empty.
 */
trait PreparesQueries {

	/**
	 * A prepared statement.
	 *
	 * @param wpdb             $db        WordPress database.
	 * @param literal-string   $query     Query with placeholders; always fixed SQL.
	 * @param int|string|float ...$values Values.
	 * @throws RuntimeException When WordPress can't prepare the query.
	 */
	private static function prepared( wpdb $db, string $query, int|string|float ...$values ): string {
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- this is the prepare call.
		$prepared = $db->prepare( $query, ...$values );
		if ( ! is_string( $prepared ) ) {
			throw new RuntimeException( 'A query could not be prepared.' );
		}

		return $prepared;
	}

	/**
	 * A comma-separated list of integers for an IN clause; integers need no quoting.
	 *
	 * @param list<int> $ids IDs.
	 */
	private static function idList( array $ids ): string {
		return implode( ',', array_map( 'intval', $ids ) );
	}
}
