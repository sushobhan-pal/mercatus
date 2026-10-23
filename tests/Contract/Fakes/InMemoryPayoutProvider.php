<?php
/**
 * In-memory PayoutProvider for tests.
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Contract\Fakes;

use InvalidArgumentException;
use Mercatus\Core\Contracts\Payouts\PayoutInstruction;
use Mercatus\Core\Contracts\Payouts\PayoutMode;
use Mercatus\Core\Contracts\Payouts\PayoutProvider;
use Mercatus\Core\Contracts\Payouts\PayoutResult;
use Mercatus\Core\Contracts\Payouts\PayoutStatus;

/**
 * Records payouts by idempotency key. Payouts to the account reference DECLINED_ACCOUNT fail.
 */
final class InMemoryPayoutProvider implements PayoutProvider {

	public const DECLINED_ACCOUNT = 'acct_declined';

	/**
	 * Results by idempotency key.
	 *
	 * @var array<string, PayoutResult>
	 */
	private array $byKey = array();

	/**
	 * Results by provider reference.
	 *
	 * @var array<string, PayoutResult>
	 */
	private array $byReference = array();

	public function id(): string {
		return 'in-memory';
	}

	public function modes(): array {
		return array( PayoutMode::Payout, PayoutMode::Split );
	}

	public function send( PayoutInstruction $instruction ): PayoutResult {
		if ( isset( $this->byKey[ $instruction->idempotencyKey ] ) ) {
			return $this->byKey[ $instruction->idempotencyKey ];
		}

		$reference = 'po_' . ( count( $this->byReference ) + 1 );
		$result    = self::DECLINED_ACCOUNT === $instruction->vendorAccountRef
			? new PayoutResult( PayoutStatus::Failed, $reference, 'account_closed' )
			: new PayoutResult( PayoutStatus::Pending, $reference );

		$this->byKey[ $instruction->idempotencyKey ] = $result;
		$this->byReference[ $reference ]             = $result;

		return $result;
	}

	public function status( string $provider_reference ): PayoutResult {
		if ( ! isset( $this->byReference[ $provider_reference ] ) ) {
			throw new InvalidArgumentException( 'Unknown payout reference.' );
		}

		return $this->byReference[ $provider_reference ];
	}
}
