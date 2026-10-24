<?php
/**
 * A claim source with fixed claims that counts its calls.
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Fixtures\Identity;

use Mercatus\Core\Contracts\Identity\ActorClaim;
use Mercatus\Core\Contracts\Identity\ClaimSource;

/**
 * Proves when the resolver asks its sources and when the cache answers instead.
 */
final class CountingClaimSource implements ClaimSource {

	public int $calls = 0;

	/**
	 * Creates the source.
	 *
	 * @param list<ActorClaim> $fixed Claims for every user.
	 */
	public function __construct( private array $fixed ) {}

	public function claims( int $user_id ): array {
		++$this->calls;

		return $this->fixed;
	}
}
