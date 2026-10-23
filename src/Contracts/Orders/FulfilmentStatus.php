<?php
/**
 * A vendor order's fulfilment status (ORD-8).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Contracts\Orders;

/**
 * Shipping progress, separate from the WooCommerce order status.
 */
enum FulfilmentStatus: string {
	case Unfulfilled      = 'unfulfilled';
	case PartiallyShipped = 'partially_shipped';
	case Shipped          = 'shipped';
	case Delivered        = 'delivered';
}
