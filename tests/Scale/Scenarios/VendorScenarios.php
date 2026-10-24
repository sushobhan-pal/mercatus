<?php
/**
 * core.vendor's scale scenarios (core.vendor §9, PLAT-10).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Scale\Scenarios;

use Mercatus\Core\Contracts\Cache\CachePort;
use Mercatus\Core\Contracts\Identity\IdentityRevisions;
use Mercatus\Core\Contracts\Settings\SettingsReader;
use Mercatus\Core\Identity\Application\IdentityResolver;
use Mercatus\Core\Identity\Application\ModuleExtensions;
use Mercatus\Core\Kernel\Container;
use Mercatus\Core\Kernel\Kernel;
use Mercatus\Core\Tests\Scale\Support\ScaleScenario;
use Mercatus\Core\Vendor\Application\VendorDirectory;
use Mercatus\Core\Vendor\Infrastructure\ApplicationStore;
use RuntimeException;
use WP_REST_Request;
use WP_User;

/**
 * With the seeded vendors: a vendor's identity costs one indexed query cold (the vendors.user_id lookup) and
 * none warm; a page of the review queue or of vendors is one query, with no per-row queries or COUNT; an
 * application with its history is two. Approving costs 13 whatever the number of vendors: the attempt and the
 * existing-vendor check (2), its transaction (6: start, the decision, the vendor row, the event, the audit
 * entry, commit), the committing request's outbox claim, read and finish (3), the approval email's read of
 * the attempt (1), and the response's (1).
 */
final class VendorScenarios {

	private const ADMINISTRATOR = 1;

	/**
	 * Pending applications for the approval scenario: its warm-up, the counted run and the timed runs.
	 */
	private const PENDING = 30;

	/**
	 * The scenarios.
	 *
	 * @return list<ScaleScenario>
	 * @throws RuntimeException When the site's kernel didn't boot or wasn't seeded with vendors.
	 */
	public static function all(): array {
		$services = ( Kernel::instance() ?? throw new RuntimeException( 'The kernel did not boot on the scale site.' ) )->container();
		$vendor   = get_user_by( 'login', 'seed-vendor-0001' );
		if ( ! $vendor instanceof WP_User ) {
			throw new RuntimeException( 'The scale site has no seeded vendors; rebuild the dump with bin/scale.sh dump.' );
		}
		$application = $services->get( ApplicationStore::class )->history( $vendor->ID )[0]->id;
		$pending     = self::pendingApplications( $services );

		return array(
			new ScaleScenario(
				'vendor.identity.cold',
				1,
				static function () use ( $services, $vendor ): mixed {
					$services->get( IdentityRevisions::class )->bump( $vendor->ID );

					return self::resolver( $services )->resolve( $vendor->ID );
				}
			),
			new ScaleScenario( 'vendor.identity.warm', 0, static fn(): mixed => self::resolver( $services )->resolve( $vendor->ID ) ),
			new ScaleScenario( 'vendor.applications.page', 1, static fn(): mixed => self::asAdministrator( 'GET', '/vendor-applications', array( 'status' => 'approved' ) ) ),
			new ScaleScenario( 'vendor.vendors.page', 1, static fn(): mixed => self::asAdministrator( 'GET', '/vendors', array( 'status' => 'active' ) ) ),
			new ScaleScenario( 'vendor.application.read', 2, static fn(): mixed => self::asAdministrator( 'GET', '/vendor-applications/' . $application ) ),
			new ScaleScenario(
				'vendor.application.approve',
				13,
				static function () use ( &$pending ): mixed {
					return self::asAdministrator( 'POST', '/vendor-applications/' . array_shift( $pending ) . '/approve' );
				}
			),
		);
	}

	/**
	 * Customers with a pending application each, created before anything is counted.
	 *
	 * @param Container $services Services.
	 * @return list<int> Application IDs.
	 */
	private static function pendingApplications( Container $services ): array {
		$ids = array();
		for ( $n = 1; $n <= self::PENDING; $n++ ) {
			$user_id = wp_insert_user(
				array(
					'user_login' => sprintf( 'scale-applicant-%02d', $n ),
					'user_email' => sprintf( 'scale-applicant-%02d@example.test', $n ),
					'user_pass'  => 'scale-applicant',
					'role'       => 'customer',
				)
			);
			if ( is_wp_error( $user_id ) ) {
				throw new RuntimeException( esc_html( $user_id->get_error_message() ) );
			}
			wp_set_current_user( $user_id );
			$ids[] = (int) $services->get( VendorDirectory::class )->register( array( 'store_name' => sprintf( 'Scale applicant %02d', $n ) ) )['application']['id'];
		}
		wp_set_current_user( 0 );

		return $ids;
	}

	/**
	 * A resolver as the next request builds it, sharing only the cache.
	 *
	 * @param Container $services Services.
	 */
	private static function resolver( Container $services ): IdentityResolver {
		return new IdentityResolver( $services->get( CachePort::class ), $services->get( ModuleExtensions::class ), $services->get( SettingsReader::class ) );
	}

	/**
	 * Calls an endpoint as the scale site's administrator.
	 *
	 * @param string               $method Method.
	 * @param string               $route  Route below the namespace.
	 * @param array<string, mixed> $query  Query arguments.
	 */
	private static function asAdministrator( string $method, string $route, array $query = array() ): mixed {
		wp_set_current_user( self::ADMINISTRATOR );
		$request = new WP_REST_Request( $method, '/mercatus/v1' . $route );
		$request->set_query_params( $query );

		return rest_do_request( $request );
	}
}
