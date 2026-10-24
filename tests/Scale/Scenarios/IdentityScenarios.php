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
use Mercatus\Core\Kernel\Kernel;
use Mercatus\Core\Tests\Scale\Support\ScaleScenario;
use RuntimeException;
use WP_REST_Request;

/**
 * Resolving the scale site's administrator as a new request would, and GET /me. Neither grows with the data
 * set; a cold identity costs one indexed query per claim source once core.vendor adds one.
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
		);
	}
}
