<?php
/**
 * Runs the PayoutProvider conformance tests against the in-memory fake.
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Contract\Fakes;

use Mercatus\Core\Contracts\Payouts\PayoutInstruction;
use Mercatus\Core\Contracts\Payouts\PayoutProvider;
use Mercatus\Core\Shared\Money\Money;
use Mercatus\Core\Tests\Contract\Conformance\PayoutProviderConformanceTrait;
use PHPUnit\Framework\TestCase;

/**
 * Proves the trait and the fake agree.
 */
final class InMemoryPayoutProviderTest extends TestCase {

	use PayoutProviderConformanceTrait;

	protected function createPayoutProvider(): PayoutProvider {
		return new InMemoryPayoutProvider();
	}

	protected function acceptedPayoutInstruction( string $idempotency_key ): PayoutInstruction {
		return new PayoutInstruction( $idempotency_key, 7, Money::of( 150000, 'INR' ), 'acct_vendor_7' );
	}

	protected function declinedPayoutInstructions(): array {
		return array( new PayoutInstruction( 'conformance-declined', 8, Money::of( 1000, 'INR' ), InMemoryPayoutProvider::DECLINED_ACCOUNT ) );
	}
}
