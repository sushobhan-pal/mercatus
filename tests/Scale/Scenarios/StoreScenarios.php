<?php
/**
 * core.store's scale scenarios (core.store §9, PLAT-10).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Scale\Scenarios;

use Mercatus\Core\Contracts\Cache\CachePort;
use Mercatus\Core\Kernel\Kernel;
use Mercatus\Core\Store\Infrastructure\StoreRepository;
use Mercatus\Core\Tests\Scale\Support\ScaleScenario;
use Mercatus\Core\Vendor\Infrastructure\VendorStore;
use RuntimeException;
use WP_REST_Request;
use WP_User;

/**
 * With the seeded stores: a store page costs no query warm and one cold (PLAT-10 allows two); a page of the
 * store list is one query, with no per-row queries or COUNT; and a vendor's /me, with its store section, stays
 * at no query warm.
 */
final class StoreScenarios {

	/**
	 * The scenarios.
	 *
	 * @return list<ScaleScenario>
	 * @throws RuntimeException When the site's kernel didn't boot or wasn't seeded with stores.
	 */
	public static function all(): array {
		$services = ( Kernel::instance() ?? throw new RuntimeException( 'The kernel did not boot on the scale site.' ) )->container();
		$user     = get_user_by( 'login', 'seed-vendor-0001' );
		$vendor   = $user instanceof WP_User ? $services->get( VendorStore::class )->byUserId( $user->ID ) : null;
		$pair     = null === $vendor ? null : $services->get( StoreRepository::class )->byVendorId( $vendor->id );
		if ( ! $user instanceof WP_User || null === $pair ) {
			throw new RuntimeException( 'The scale site has no seeded stores; rebuild the dump with bin/scale.sh dump.' );
		}
		$slug = $pair[0]->slug;

		return array(
			new ScaleScenario( 'store.page', 0, static fn(): mixed => self::get( 0, '/stores/' . $slug ) ),
			new ScaleScenario(
				'store.page.cold',
				1,
				static function () use ( $services, $slug ): mixed {
					$services->get( CachePort::class )->flushGroup( 'mercatus_stores' );

					return self::get( 0, '/stores/' . $slug );
				}
			),
			new ScaleScenario( 'store.list.page', 1, static fn(): mixed => self::get( 0, '/stores' ) ),
			new ScaleScenario( 'me.read.vendor', 0, static fn(): mixed => self::get( $user->ID, '/me' ) ),
		);
	}

	/**
	 * A GET as a user, or as a visitor.
	 *
	 * @param int    $user_id User; 0 for a visitor.
	 * @param string $route   Route below the namespace.
	 */
	private static function get( int $user_id, string $route ): mixed {
		wp_set_current_user( $user_id );

		return rest_do_request( new WP_REST_Request( 'GET', '/mercatus/v1' . $route ) );
	}
}
