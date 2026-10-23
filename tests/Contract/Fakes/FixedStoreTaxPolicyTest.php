<?php
/**
 * Runs the StoreTaxPolicy conformance tests against the fixed fake.
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Contract\Fakes;

use Mercatus\Core\Contracts\Tax\StoreTaxPolicy;
use Mercatus\Core\Tests\Contract\Conformance\StoreTaxPolicyConformanceTrait;
use PHPUnit\Framework\TestCase;

/**
 * Proves the trait and the fake agree.
 */
final class FixedStoreTaxPolicyTest extends TestCase {

	use StoreTaxPolicyConformanceTrait;

	protected function createStoreTaxPolicy( bool $enabled ): StoreTaxPolicy {
		return new FixedStoreTaxPolicy( $enabled );
	}
}
