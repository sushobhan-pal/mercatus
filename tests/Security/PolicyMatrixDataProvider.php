<?php
/**
 * Actor × route denial cases generated from the route registry (TEST-2).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Security;

use Mercatus\Core\Tests\Support\Routes\RouteOperation;
use Mercatus\Core\Tests\Support\Routes\RouteOperations;
use WP_REST_Server;

/**
 * Every route's access level says which actors must be refused and with which status (SEC-4: another
 * vendor's resource is 404, a refusal inside one's own tenant is 403). New routes are covered as soon as
 * they're registered. This is the Phase 0 convention; core.identity extends it with named permissions,
 * staff scope and the remaining SEC-1 actors.
 */
final class PolicyMatrixDataProvider {

	public const ANONYMOUS = 'anonymous';

	public const CUSTOMER = 'customer';

	public const VENDOR_OWNER = 'vendor_owner';

	/**
	 * Owner of a different store from the one the resource belongs to.
	 */
	public const OTHER_VENDOR_OWNER = 'other_vendor_owner';

	/**
	 * Expected status per refused actor, by access level.
	 */
	private const DENIALS = array(
		RouteOperation::ACCESS_PUBLIC        => array(),
		RouteOperation::ACCESS_AUTHENTICATED => array( self::ANONYMOUS => 401 ),
		RouteOperation::ACCESS_TENANT        => array(
			self::ANONYMOUS          => 401,
			self::CUSTOMER           => 403,
			self::OTHER_VENDOR_OWNER => 404,
		),
		RouteOperation::ACCESS_ADMIN         => array(
			self::ANONYMOUS    => 401,
			self::CUSTOMER     => 403,
			self::VENDOR_OWNER => 403,
		),
	);

	/**
	 * Denial cases for the routes registered on a live REST server. Needs WordPress.
	 *
	 * @param WP_REST_Server $server REST server after rest_api_init.
	 * @return array<string, array{RouteOperation, string, int}>
	 */
	public static function fromServer( WP_REST_Server $server ): array {
		return self::denials( RouteOperations::fromServer( $server ) );
	}

	/**
	 * Denial cases keyed "METHOD /path as actor", each as (operation, actor, expected status).
	 *
	 * @param list<RouteOperation> $operations Operations to cover.
	 * @return array<string, array{RouteOperation, string, int}>
	 */
	public static function denials( array $operations ): array {
		$cases = array();
		foreach ( $operations as $operation ) {
			foreach ( self::DENIALS[ $operation->access ] ?? array() as $actor => $status ) {
				$cases[ $operation->label() . ' as ' . $actor ] = array( $operation, $actor, $status );
			}
		}

		return $cases;
	}
}
