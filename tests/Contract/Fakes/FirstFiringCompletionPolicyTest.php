<?php
/**
 * Runs the OrderCompletionPolicy conformance tests against the reference fake.
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Contract\Fakes;

use Mercatus\Core\Contracts\Orders\OrderCompletionPolicy;
use Mercatus\Core\Tests\Contract\Conformance\OrderCompletionPolicyConformanceTrait;
use PHPUnit\Framework\TestCase;

/**
 * Proves the trait and the fake agree.
 */
final class FirstFiringCompletionPolicyTest extends TestCase {

	use OrderCompletionPolicyConformanceTrait;

	protected function createOrderCompletionPolicy( array $triggers ): OrderCompletionPolicy {
		return new FirstFiringCompletionPolicy( $triggers );
	}
}
