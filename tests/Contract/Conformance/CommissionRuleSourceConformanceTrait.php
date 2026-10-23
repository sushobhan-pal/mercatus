<?php
/**
 * Conformance tests every CommissionRuleSource passes (TEST-1 Layer 3, MON-1).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Contract\Conformance;

use Mercatus\Core\Contracts\Commission\CommissionContext;
use Mercatus\Core\Contracts\Commission\CommissionRuleSource;
use Mercatus\Core\Tests\Contract\Support\ContractIdentifier;

/**
 * Use in a TestCase and implement the three methods below.
 */
trait CommissionRuleSourceConformanceTrait {

	/**
	 * Returns the source under test.
	 */
	abstract protected function createCommissionRuleSource(): CommissionRuleSource;

	/**
	 * Returns a line the source has a rule for.
	 */
	abstract protected function commissionContextWithRule(): CommissionContext;

	/**
	 * Returns lines the source has no rule for; empty for a source that always has one (the global source).
	 *
	 * @return list<CommissionContext>
	 */
	abstract protected function commissionContextsWithoutRule(): array;

	public function testCommissionRuleSourceHasAStableIdAndPriority(): void {
		$source = $this->createCommissionRuleSource();

		$this->assertMatchesRegularExpression( ContractIdentifier::PATTERN, $source->id() );
		$this->assertSame( $source->id(), $source->id() );
		$this->assertSame( $source->priority(), $source->priority() );
	}

	public function testCommissionRuleSourceReturnsTheSameRuleForTheSameLine(): void {
		$source  = $this->createCommissionRuleSource();
		$context = $this->commissionContextWithRule();
		$rule    = $source->ruleFor( $context );

		$this->assertNotNull( $rule );
		$this->assertEquals( $rule, $source->ruleFor( $context ) );
	}

	public function testCommissionRuleSourceReturnsNullWithoutARule(): void {
		$contexts = $this->commissionContextsWithoutRule();
		if ( array() === $contexts ) {
			$this->markTestSkipped( 'This source has a rule for every line.' );
		}

		$source = $this->createCommissionRuleSource();
		foreach ( $contexts as $context ) {
			$this->assertNull( $source->ruleFor( $context ) );
		}
	}
}
