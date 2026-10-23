<?php
/**
 * A payment provider that pays vendors (PRO-3, PRO-5).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Contracts\Payouts;

/**
 * Stripe and PayPal come first. Payout mode sends money after earnings are released (MON-9); split mode's
 * checkout-time transfers are specified in the payment-integrations module's decision file.
 */
interface PayoutProvider {

	/**
	 * Stable identifier, for example stripe or paypal.
	 */
	public function id(): string;

	/**
	 * Modes this provider supports; never empty.
	 *
	 * @return list<PayoutMode>
	 */
	public function modes(): array;

	/**
	 * Sends a payout. Sending the same idempotency key again returns the original payout, never a second one.
	 * A payout the provider declines is a Failed result, not an exception.
	 *
	 * @param PayoutInstruction $instruction Payout to send.
	 * @throws \RuntimeException When the provider can't be reached; retry with the same idempotency key.
	 * @phpstan-impure
	 */
	public function send( PayoutInstruction $instruction ): PayoutResult;

	/**
	 * Current state of a payout sent earlier.
	 *
	 * @param string $provider_reference Reference from a previous result.
	 * @throws \InvalidArgumentException When the provider doesn't know the reference.
	 * @throws \RuntimeException When the provider can't be reached.
	 * @phpstan-impure
	 */
	public function status( string $provider_reference ): PayoutResult;
}
