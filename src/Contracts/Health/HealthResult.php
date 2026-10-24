<?php
/**
 * One health check's answer (PLAT-4).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Contracts\Health;

/**
 * A status, a mercatus_* code that the dashboard translates, and details without personal data.
 */
final readonly class HealthResult {

	/**
	 * Creates the result.
	 *
	 * @param HealthStatus         $status  Status.
	 * @param string               $code    Code, for example mercatus_no_persistent_cache.
	 * @param array<string, mixed> $details Numbers and IDs that explain it.
	 */
	public function __construct( public HealthStatus $status, public string $code, public array $details = array() ) {}
}
