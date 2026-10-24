<?php
/**
 * core.identity's scale scenarios (core.identity §9, PLAT-10).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Scale\Scenarios;

use Mercatus\Core\Contracts\Cache\CachePort;
use Mercatus\Core\Contracts\Settings\SettingsReader;
use Mercatus\Core\Identity\Application\IdentityResolver;
use Mercatus\Core\Identity\Application\ModuleExtensions;
use Mercatus\Core\Identity\Application\RateLimiter;
use Mercatus\Core\Identity\Infrastructure\IdempotentRequests;
use Mercatus\Core\Kernel\Kernel;
use Mercatus\Core\Tests\Scale\Support\ScaleScenario;
use RuntimeException;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Resolving the scale site's administrator as a new request would, GET /me with and without a known ETag,
 * a rate-limit check and an idempotent POST's own cost (claim, then complete). None grows with the data set;
 * a cold identity costs one indexed query per claim source that reads a table, as VendorScenarios measures.
 */
final class IdentityScenarios {

	private const ADMINISTRATOR = 1;

	/**
	 * The scenarios.
	 *
	 * @return list<ScaleScenario>
	 * @throws RuntimeException When the site's kernel didn't boot.
	 */
	public static function all(): array {
		$kernel   = Kernel::instance() ?? throw new RuntimeException( 'The kernel did not boot on the scale site.' );
		$services = $kernel->container();

		$keys = 0;

		return array(
			new ScaleScenario(
				'identity.resolve',
				0,
				static fn(): mixed => ( new IdentityResolver( $services->get( CachePort::class ), $services->get( ModuleExtensions::class ), $services->get( SettingsReader::class ) ) )->resolve( self::ADMINISTRATOR )
			),
			new ScaleScenario(
				'me.read',
				0,
				static function (): void {
					wp_set_current_user( self::ADMINISTRATOR );
					rest_do_request( new WP_REST_Request( 'GET', '/mercatus/v1/me' ) );
				}
			),
			new ScaleScenario(
				'me.not_modified',
				0,
				static function (): void {
					wp_set_current_user( self::ADMINISTRATOR );
					$etag    = rest_do_request( new WP_REST_Request( 'GET', '/mercatus/v1/me' ) )->get_headers()['ETag'] ?? '';
					$request = new WP_REST_Request( 'GET', '/mercatus/v1/me' );
					$request->set_header( 'If-None-Match', is_string( $etag ) ? $etag : '' );
					rest_do_request( $request );
				}
			),
			new ScaleScenario( 'ratelimit.hit', 0, static fn(): mixed => $services->get( RateLimiter::class )->hit( 'ip:203.0.113.9', 'visitor', false, 'GET /scale' ) ),
			new ScaleScenario(
				'idempotency.post',
				2,
				static function () use ( $services, &$keys ): void {
					$request = new WP_REST_Request( 'POST', '/mercatus/v1/scale' );
					$request->set_header( 'Idempotency-Key', 'scale-' . ( ++$keys ) . '-' . wp_generate_password( 8, false ) );
					$services->get( IdempotentRequests::class )->handle( $request, false, static fn(): WP_REST_Response => new WP_REST_Response( array(), 201 ) );
				}
			),
		);
	}
}
