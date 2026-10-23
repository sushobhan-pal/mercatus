<?php
/**
 * Which outside systems see child orders (ORD-7).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Contracts\Orders;

/**
 * The single decision point for child-order visibility. Version 1 hides child orders from WooCommerce
 * Analytics, My Account orders and WooCommerce customer emails. A new context, such as a tax plugin's order
 * sync, is added here and nowhere else.
 */
interface ChildOrderExposure {

	public const CONTEXT_ANALYTICS = 'analytics';

	public const CONTEXT_MY_ACCOUNT = 'my_account';

	public const CONTEXT_CUSTOMER_EMAILS = 'customer_emails';

	/**
	 * Whether a child order is visible in an outside-system context.
	 *
	 * @param string $context        One of the CONTEXT_* constants, or a context an adapter defines.
	 * @param int    $child_order_id WooCommerce ID of the child order.
	 */
	public function isVisibleIn( string $context, int $child_order_id ): bool;
}
