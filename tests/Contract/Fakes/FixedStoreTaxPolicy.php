<?php
/**
 * StoreTaxPolicy with a fixed answer, for tests.
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Contract\Fakes;

use Mercatus\Core\Contracts\Tax\StoreTaxPolicy;

/**
 * Store tax on or off for every vendor.
 */
final class FixedStoreTaxPolicy implements StoreTaxPolicy {

	/**
	 * Creates the policy.
	 *
	 * @param bool $enabled Answer for every vendor.
	 */
	public function __construct( private bool $enabled ) {}

	public function isEnabledFor( int $vendor_id ): bool {
		return $this->enabled;
	}
}
