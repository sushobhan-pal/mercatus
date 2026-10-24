<?php
/**
 * Merges repeated bulk events (API-3, KER-7).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Infrastructure\Outbox;

/**
 * Of claimed bulk events that share a coalesce key, only the newest is handled; the older ones are marked
 * superseded. Events without a key are all handled.
 */
final class BulkEventCoalescer {

	/**
	 * Splits records into those to handle and the IDs superseded by a newer one.
	 *
	 * @param list<OutboxRecord> $records Claimed records, in any order.
	 * @return array{list<OutboxRecord>, list<int>}
	 */
	public function coalesce( array $records ): array {
		$newest = array();
		foreach ( $records as $record ) {
			$key = $record->event->coalesceKey;
			if ( null !== $key && ( ! isset( $newest[ $key ] ) || $record->id > $newest[ $key ]->id ) ) {
				$newest[ $key ] = $record;
			}
		}

		$handle     = array();
		$superseded = array();
		foreach ( $records as $record ) {
			$key = $record->event->coalesceKey;
			if ( null === $key || $newest[ $key ] === $record ) {
				$handle[] = $record;
			} else {
				$superseded[] = $record->id;
			}
		}
		usort( $handle, static fn( OutboxRecord $a, OutboxRecord $b ): int => $a->id <=> $b->id );
		sort( $superseded );

		return array( $handle, $superseded );
	}
}
