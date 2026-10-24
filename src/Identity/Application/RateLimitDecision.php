<?php
/**
 * The outcome of counting a request (PLAT-8, IDN-10).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Identity\Application;

/**
 * Its numbers become the RateLimit-* headers; a refusal is 429 with Retry-After.
 */
final readonly class RateLimitDecision {

	/**
	 * Creates the decision.
	 *
	 * @param bool     $allowed   Whether the request may proceed.
	 * @param int|null $limit     Requests allowed in the window; null when nothing is limited.
	 * @param int      $remaining Requests left in the window.
	 * @param int      $reset     Seconds until the window ends.
	 */
	public function __construct( public bool $allowed, public ?int $limit = null, public int $remaining = 0, public int $reset = 0 ) {}

	/**
	 * Nothing limits the request: no persistent cache, or an exempt actor.
	 */
	public static function unlimited(): self {
		return new self( true );
	}
}
