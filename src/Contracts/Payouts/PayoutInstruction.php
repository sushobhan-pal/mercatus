<?php
/**
 * A payout to send (PRO-5).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Contracts\Payouts;

use InvalidArgumentException;
use Mercatus\Core\Shared\Money\Money;

/**
 * One payout of a vendor's withdrawable earnings. The idempotency key makes retries safe: sending the same
 * key twice never pays twice.
 */
final readonly class PayoutInstruction {

	/**
	 * Creates a payout instruction.
	 *
	 * @param string $idempotencyKey   Unique per payout attempt series; reused on retries.
	 * @param int    $vendorId         Vendor being paid.
	 * @param Money  $amount           Amount to pay, more than zero.
	 * @param string $vendorAccountRef The vendor's connected account at the provider.
	 * @throws InvalidArgumentException When a field is empty, the vendor ID isn't positive or the amount isn't.
	 */
	public function __construct(
		public string $idempotencyKey,
		public int $vendorId,
		public Money $amount,
		public string $vendorAccountRef,
	) {
		if ( '' === $idempotencyKey || '' === $vendorAccountRef || $vendorId < 1 || ! $amount->isPositive() ) {
			throw new InvalidArgumentException( 'A payout needs an idempotency key, a vendor, an account reference and a positive amount.' );
		}
	}
}
