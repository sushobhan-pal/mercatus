<?php
/**
 * In-memory CommissionRuleSource for tests.
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Contract\Fakes;

use Mercatus\Core\Contracts\Commission\CommissionContext;
use Mercatus\Core\Contracts\Commission\CommissionRule;
use Mercatus\Core\Contracts\Commission\CommissionRuleSource;

/**
 * Returns a fixed rule per vendor.
 */
final class InMemoryCommissionRuleSource implements CommissionRuleSource {

	/**
	 * Creates the source.
	 *
	 * @param string                    $id            Source ID.
	 * @param int                       $priority      Source priority.
	 * @param array<int, CommissionRule> $rulesByVendor Rules by vendor ID.
	 */
	public function __construct( private string $id, private int $priority, private array $rulesByVendor ) {}

	public function id(): string {
		return $this->id;
	}

	public function priority(): int {
		return $this->priority;
	}

	public function ruleFor( CommissionContext $context ): ?CommissionRule {
		return $this->rulesByVendor[ $context->vendorId ] ?? null;
	}
}
