<?php
/**
 * Reports whether rate limiting is on (PLAT-4, PLAT-8).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Identity\Application;

use Mercatus\Core\Contracts\Cache\CoordinationPort;
use Mercatus\Core\Contracts\Health\HealthCheck;
use Mercatus\Core\Contracts\Health\HealthResult;
use Mercatus\Core\Contracts\Health\HealthStatus;

/**
 * Without a persistent object cache Mercatus doesn't limit requests; floods must then be stopped at the page
 * cache, CDN or firewall (AR-1).
 */
final class RateLimitingHealthCheck implements HealthCheck {

	/**
	 * Creates the check.
	 *
	 * @param CoordinationPort $coordination Counters.
	 */
	public function __construct( private CoordinationPort $coordination ) {}

	public function id(): string {
		return 'identity.rate_limiting';
	}

	public function check(): HealthResult {
		return $this->coordination->isAvailable()
			? new HealthResult( HealthStatus::Ok, 'mercatus_rate_limiting_on' )
			: new HealthResult( HealthStatus::Warning, 'mercatus_rate_limiting_off', array( 'advice' => 'Limit requests at the page cache, CDN or firewall.' ) );
	}
}
