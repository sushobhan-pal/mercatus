<?php
/**
 * A commission rule as a source returns it (MON-1, MON-7).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Contracts\Commission;

use InvalidArgumentException;
use Mercatus\Core\Shared\Money\Money;

/**
 * A flat, percent, or flat + percent rule, with optional overrides of the global coupon, shipping and tax
 * settings (MON-3 to MON-5); null means "use the global setting". The rule ID and version are snapshotted
 * on the allocation and never recalculated.
 */
final readonly class CommissionRule {

	/**
	 * Creates a rule.
	 *
	 * @param string      $ruleId              Stable rule identifier.
	 * @param int         $version             Rule version, 1 or more.
	 * @param string|null $percent             Exact decimal string from 0 to 100.
	 * @param Money|null  $flat                Flat amount, zero or more.
	 * @param bool|null   $deductVendorCoupons Override of "Commission after considering vendor coupon?".
	 * @param bool|null   $deductAdminCoupons  Override of "Commission after considering admin coupon?".
	 * @param bool|null   $shippingToVendor    Override of "Shipping cost goes to vendor?".
	 * @param bool|null   $taxToVendor         Override of "Tax goes to vendor?" (store tax off only).
	 * @throws InvalidArgumentException When the rule is malformed or has neither a percent nor a flat amount.
	 */
	public function __construct(
		public string $ruleId,
		public int $version,
		public ?string $percent = null,
		public ?Money $flat = null,
		public ?bool $deductVendorCoupons = null,
		public ?bool $deductAdminCoupons = null,
		public ?bool $shippingToVendor = null,
		public ?bool $taxToVendor = null,
	) {
		if ( '' === $ruleId || $version < 1 ) {
			throw new InvalidArgumentException( 'A commission rule needs an ID and a version of 1 or more.' );
		}
		if ( null === $percent && null === $flat ) {
			throw new InvalidArgumentException( 'A commission rule has a percent, a flat amount, or both.' );
		}
		if ( null !== $percent && ( 1 !== preg_match( '/^\d+(\.\d+)?$/', $percent ) || (float) $percent > 100 ) ) {
			throw new InvalidArgumentException( 'A commission percent is a decimal string from 0 to 100.' );
		}
		if ( null !== $flat && $flat->minor < 0 ) {
			throw new InvalidArgumentException( 'A flat commission is zero or more.' );
		}
	}
}
