<?php
/**
 * A module's health check (ARCH-6, PLAT-4).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Contracts\Health;

/**
 * Listed in a module manifest's `healthChecks` and reported by GET /health. Checks must be cheap: a few
 * indexed queries at most.
 */
interface HealthCheck {

	/**
	 * Stable ID, for example core.tax.vendor_rates.
	 */
	public function id(): string;

	/**
	 * Runs the check.
	 */
	public function check(): HealthResult;
}
