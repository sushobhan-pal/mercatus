<?php
/**
 * What completion triggers look at (ORD-12).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Contracts\Orders;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A vendor order's state as completion triggers see it. Triggers that need their own data, such as an OTP
 * check, look it up by the vendor order ID.
 */
final readonly class VendorOrderCompletionState {

	/**
	 * Creates the state.
	 *
	 * @param int                    $vendorOrderId            Vendor order.
	 * @param bool                   $paid                     Whether the parent order is paid.
	 * @param bool                   $digitalOnly              Whether every item is virtual and downloadable.
	 * @param FulfilmentStatus       $fulfilment               Current fulfilment status.
	 * @param DateTimeImmutable|null $shippedAt                When it was marked shipped.
	 * @param DateTimeImmutable|null $deliveredAt              When it was marked delivered.
	 * @param bool                   $vendorMarkedCompleted    Whether the vendor marked it completed.
	 * @param bool                   $customerConfirmedReceipt Whether the customer confirmed receipt.
	 * @param bool                   $openRefundOrReturn       Whether a refund or return request is open; timer triggers pause.
	 * @param DateTimeImmutable      $now                      Current time, for timer-based triggers.
	 * @throws InvalidArgumentException When the vendor order ID isn't positive.
	 */
	public function __construct(
		public int $vendorOrderId,
		public bool $paid,
		public bool $digitalOnly,
		public FulfilmentStatus $fulfilment,
		public ?DateTimeImmutable $shippedAt,
		public ?DateTimeImmutable $deliveredAt,
		public bool $vendorMarkedCompleted,
		public bool $customerConfirmedReceipt,
		public bool $openRefundOrReturn,
		public DateTimeImmutable $now,
	) {
		if ( $vendorOrderId < 1 ) {
			throw new InvalidArgumentException( 'A vendor order ID is a positive integer.' );
		}
	}
}
