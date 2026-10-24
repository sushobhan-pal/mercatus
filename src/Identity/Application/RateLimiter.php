<?php
/**
 * Counts requests per actor and minute (PLAT-8, IDN-10, IDN-19).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Identity\Application;

use Mercatus\Core\Contracts\Cache\CoordinationPort;
use Mercatus\Core\Contracts\Settings\SettingsReader;

/**
 * A fixed one-minute window per actor and bucket (reads, writes): one counter increment per request. A route
 * with its own tighter limit counts in a second, per-route counter. Without a persistent object cache nothing
 * is limited (PLAT-8, AR-1).
 */
final class RateLimiter {

	private const WINDOW = 60;

	/**
	 * Setting keys by actor kind and bucket.
	 */
	private const LIMITS = array(
		'visitor' => array( IdentitySettings::RATE_LIMIT_VISITOR_READS, IdentitySettings::RATE_LIMIT_VISITOR_WRITES ),
		'user'    => array( IdentitySettings::RATE_LIMIT_USER_READS, IdentitySettings::RATE_LIMIT_USER_WRITES ),
		'client'  => array( IdentitySettings::RATE_LIMIT_CLIENT_READS, IdentitySettings::RATE_LIMIT_CLIENT_WRITES ),
	);

	/**
	 * Creates the limiter.
	 *
	 * @param CoordinationPort $coordination Counters.
	 * @param SettingsReader   $settings     Limits.
	 * @param \Closure(): int  $now          Current Unix time.
	 */
	public function __construct( private CoordinationPort $coordination, private SettingsReader $settings, private \Closure $now ) {}

	/**
	 * Counts a request.
	 *
	 * @param string      $actor      Actor key: client:ID, user:ID or ip:ADDRESS.
	 * @param string      $kind       client, user or visitor.
	 * @param bool        $write      Whether it's a write (anything but GET and HEAD).
	 * @param string      $route      The route, for a route's own limit.
	 * @param int|null    $route_limit The route's own per-minute limit, if any.
	 */
	public function hit( string $actor, string $kind, bool $write, string $route, ?int $route_limit = null ): RateLimitDecision {
		if ( ! $this->coordination->isAvailable() ) {
			return RateLimitDecision::unlimited();
		}
		$limits   = self::LIMITS[ $kind ] ?? self::LIMITS['visitor'];
		$limit    = $this->settings->int( $limits[ $write ? 1 : 0 ] );
		$decision = $this->count( 'rate:' . ( $write ? 'w' : 'r' ) . ':' . $actor, $limit );
		if ( $decision->allowed && null !== $route_limit ) {
			$route_decision = $this->count( 'rate:route:' . md5( $route ) . ':' . $actor, $route_limit );
			if ( ! $route_decision->allowed || $route_decision->remaining < $decision->remaining ) {
				return $route_decision;
			}
		}

		return $decision;
	}

	/**
	 * Increments one counter and compares it with its limit.
	 *
	 * @param string $key   Counter key.
	 * @param int    $limit Requests allowed per window.
	 */
	private function count( string $key, int $limit ): RateLimitDecision {
		$count = $this->coordination->increment( $key, self::WINDOW );
		$reset = self::WINDOW - ( ( $this->now )() % self::WINDOW );
		if ( null === $count ) {
			return RateLimitDecision::unlimited();
		}

		return new RateLimitDecision( $count <= $limit, $limit, max( 0, $limit - $count ), $reset );
	}
}
