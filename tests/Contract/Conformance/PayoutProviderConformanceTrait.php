<?php
/**
 * Conformance tests every PayoutProvider passes (TEST-1 Layer 3, PRO-3, PRO-5).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Contract\Conformance;

use InvalidArgumentException;
use Mercatus\Core\Contracts\Payouts\PayoutInstruction;
use Mercatus\Core\Contracts\Payouts\PayoutMode;
use Mercatus\Core\Contracts\Payouts\PayoutProvider;
use Mercatus\Core\Contracts\Payouts\PayoutStatus;
use Mercatus\Core\Tests\Contract\Support\ContractIdentifier;

/**
 * Use in a TestCase and implement the three methods below. Provider adapters run this against the
 * provider's sandbox or a recorded fake of its API, never live accounts.
 */
trait PayoutProviderConformanceTrait {

	/**
	 * Returns the provider under test.
	 */
	abstract protected function createPayoutProvider(): PayoutProvider;

	/**
	 * Returns a payout the provider accepts, with the given idempotency key.
	 *
	 * @param string $idempotency_key Idempotency key to use.
	 */
	abstract protected function acceptedPayoutInstruction( string $idempotency_key ): PayoutInstruction;

	/**
	 * Returns payouts the provider declines; empty when the sandbox can't produce a decline.
	 *
	 * @return list<PayoutInstruction>
	 */
	abstract protected function declinedPayoutInstructions(): array;

	public function testPayoutProviderHasAStableIdAndModes(): void {
		$provider = $this->createPayoutProvider();
		$modes    = array_map( static fn( PayoutMode $mode ): string => $mode->value, $provider->modes() );

		$this->assertMatchesRegularExpression( ContractIdentifier::PATTERN, $provider->id() );
		$this->assertNotSame( array(), $modes );
		$this->assertSame( array_values( array_unique( $modes ) ), $modes );
	}

	public function testPayoutProviderAcceptsAPayout(): void {
		$result = $this->createPayoutProvider()->send( $this->acceptedPayoutInstruction( 'conformance-accepted' ) );

		$this->assertContains( $result->status, array( PayoutStatus::Pending, PayoutStatus::Paid ) );
		$this->assertNull( $result->failureCode );
	}

	public function testPayoutProviderNeverPaysTheSameKeyTwice(): void {
		$provider = $this->createPayoutProvider();
		$first    = $provider->send( $this->acceptedPayoutInstruction( 'conformance-retry' ) );
		$retry    = $provider->send( $this->acceptedPayoutInstruction( 'conformance-retry' ) );
		$other    = $provider->send( $this->acceptedPayoutInstruction( 'conformance-other' ) );

		$this->assertSame( $first->providerReference, $retry->providerReference );
		$this->assertNotSame( $first->providerReference, $other->providerReference );
	}

	public function testPayoutProviderReportsTheStatusOfASentPayout(): void {
		$provider = $this->createPayoutProvider();
		$sent     = $provider->send( $this->acceptedPayoutInstruction( 'conformance-status' ) );
		$status   = $provider->status( $sent->providerReference );

		$this->assertSame( $sent->providerReference, $status->providerReference );
		$this->assertNotSame( PayoutStatus::Failed, $status->status );
	}

	public function testPayoutProviderRejectsAnUnknownReference(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->createPayoutProvider()->status( 'conformance-unknown-reference' );
	}

	public function testPayoutProviderReturnsADeclineAsAFailedResult(): void {
		$instructions = $this->declinedPayoutInstructions();
		if ( array() === $instructions ) {
			$this->markTestSkipped( 'The sandbox has no way to decline a payout.' );
		}

		$provider = $this->createPayoutProvider();
		foreach ( $instructions as $instruction ) {
			$result = $provider->send( $instruction );
			$this->assertSame( PayoutStatus::Failed, $result->status );
			$this->assertNotNull( $result->failureCode );
		}
	}
}
