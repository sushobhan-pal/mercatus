<?php
/**
 * How a line reaches the customer (TAX-6).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Contracts\Tax;

/**
 * Fulfilment method of a cart or order line.
 */
enum FulfilmentMethod: string {
	case Delivery = 'delivery';
	case Pickup   = 'pickup';
	case Digital  = 'digital';
}
