<?php
/**
 * In-memory ChildOrderExposure for tests.
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Contract\Fakes;

use Mercatus\Core\Contracts\Orders\ChildOrderExposure;

/**
 * Hides known child orders from the version 1 contexts (ORD-7).
 */
final class InMemoryChildOrderExposure implements ChildOrderExposure {

	private const HIDDEN_CONTEXTS = array( self::CONTEXT_ANALYTICS, self::CONTEXT_MY_ACCOUNT, self::CONTEXT_CUSTOMER_EMAILS );

	/**
	 * Creates the policy.
	 *
	 * @param list<int> $childOrderIds IDs of Mercatus child orders.
	 */
	public function __construct( private array $childOrderIds ) {}

	public function isVisibleIn( string $context, int $child_order_id ): bool {
		return ! in_array( $child_order_id, $this->childOrderIds, true ) || ! in_array( $context, self::HIDDEN_CONTEXTS, true );
	}
}
