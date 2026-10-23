<?php
/**
 * What a provider says about a payout (PRO-3, PRO-5).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Contracts\Payouts;

use InvalidArgumentException;

/**
 * The provider's answer: its status, the provider's own reference, and an error code when it failed.
 */
final readonly class PayoutResult {

	/**
	 * Creates a result.
	 *
	 * @param PayoutStatus $status            Provider-side status.
	 * @param string       $providerReference Provider's ID for the payout.
	 * @param string|null  $failureCode       Provider error code, only when the payout failed.
	 * @throws InvalidArgumentException When the reference is empty or a failure code doesn't match the status.
	 */
	public function __construct(
		public PayoutStatus $status,
		public string $providerReference,
		public ?string $failureCode = null,
	) {
		if ( '' === $providerReference ) {
			throw new InvalidArgumentException( 'A payout result needs the provider reference.' );
		}
		if ( ( PayoutStatus::Failed === $status ) !== ( null !== $failureCode ) ) {
			throw new InvalidArgumentException( 'A failure code is present exactly when the payout failed.' );
		}
	}
}
