<?php
/**
 * Runs the ChildOrderExposure conformance tests against the in-memory fake.
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Contract\Fakes;

use Mercatus\Core\Contracts\Orders\ChildOrderExposure;
use Mercatus\Core\Tests\Contract\Conformance\ChildOrderExposureConformanceTrait;
use PHPUnit\Framework\TestCase;

/**
 * Proves the trait and the fake agree.
 */
final class InMemoryChildOrderExposureTest extends TestCase {

	use ChildOrderExposureConformanceTrait;

	protected function createChildOrderExposure(): ChildOrderExposure {
		return new InMemoryChildOrderExposure( array( 101 ) );
	}

	protected function childOrderId(): int {
		return 101;
	}

	protected function nonChildOrderId(): int {
		return 100;
	}
}
