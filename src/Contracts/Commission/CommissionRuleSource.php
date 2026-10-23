<?php
/**
 * A source of commission rules (MON-1).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Contracts\Commission;

/**
 * Core ships the global rule as a source; Pro modules add category, membership, vendor, product, cart-value
 * and total-sales sources. The engine asks sources in priority order and the first rule found wins.
 */
interface CommissionRuleSource {

	/**
	 * Stable identifier, for example global or pro.commissions.product.
	 */
	public function id(): string;

	/**
	 * Position in the fixed priority order; a higher number is asked first. Values are set by the commission
	 * module's decision file.
	 */
	public function priority(): int;

	/**
	 * The rule this source applies to the line, or null when it has none.
	 *
	 * @param CommissionContext $context Line being commissioned.
	 */
	public function ruleFor( CommissionContext $context ): ?CommissionRule;
}
