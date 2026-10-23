<?php
/**
 * What a round of outbox work did (API-3).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Infrastructure\Outbox;

/**
 * Counts of handled, superseded, retried and failed events.
 */
final class WorkReport {

	public int $processed = 0;

	public int $superseded = 0;

	public int $retried = 0;

	public int $failed = 0;

	/**
	 * Adds another report's counts.
	 *
	 * @param WorkReport $other Report.
	 */
	public function add( WorkReport $other ): void {
		$this->processed  += $other->processed;
		$this->superseded += $other->superseded;
		$this->retried    += $other->retried;
		$this->failed     += $other->failed;
	}
}
