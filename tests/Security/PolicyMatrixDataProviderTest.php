<?php
/**
 * The generated security matrix covers every access level (TEST-2).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Security;

use Mercatus\Core\Tests\Support\Routes\RouteOperation;
use PHPUnit\Framework\TestCase;

/**
 * Checks the case generation itself; the WordPress-backed matrix tests arrive with core.identity.
 */
final class PolicyMatrixDataProviderTest extends TestCase {

	public function testGeneratesTheDenialsForEachAccessLevel(): void {
		$operations = array(
			$this->operation( 'GET', '/stores', RouteOperation::ACCESS_PUBLIC ),
			$this->operation( 'GET', '/me', RouteOperation::ACCESS_AUTHENTICATED ),
			$this->operation( 'GET', '/me/orders/{id}', RouteOperation::ACCESS_TENANT ),
			$this->operation( 'POST', '/withdrawals/{id}/approve', RouteOperation::ACCESS_ADMIN ),
		);

		$cases = array_map(
			static fn( array $data_case ): array => array( $data_case[0]->label(), $data_case[1], $data_case[2] ),
			PolicyMatrixDataProvider::denials( $operations )
		);

		$this->assertSame(
			array(
				'GET /me as anonymous'             => array( 'GET /me', 'anonymous', 401 ),
				'GET /me/orders/{id} as anonymous' => array( 'GET /me/orders/{id}', 'anonymous', 401 ),
				'GET /me/orders/{id} as customer'  => array( 'GET /me/orders/{id}', 'customer', 403 ),
				'GET /me/orders/{id} as other_vendor_owner' => array( 'GET /me/orders/{id}', 'other_vendor_owner', 404 ),
				'POST /withdrawals/{id}/approve as anonymous' => array( 'POST /withdrawals/{id}/approve', 'anonymous', 401 ),
				'POST /withdrawals/{id}/approve as customer' => array( 'POST /withdrawals/{id}/approve', 'customer', 403 ),
				'POST /withdrawals/{id}/approve as vendor_owner' => array( 'POST /withdrawals/{id}/approve', 'vendor_owner', 403 ),
			),
			$cases
		);
	}

	public function testCoversEveryAccessLevel(): void {
		foreach ( RouteOperation::ACCESS_LEVELS as $access ) {
			$cases = PolicyMatrixDataProvider::denials( array( $this->operation( 'GET', '/probe', $access ) ) );

			$this->assertSame( RouteOperation::ACCESS_PUBLIC === $access, array() === $cases, $access );
		}
	}

	/**
	 * An operation without arguments.
	 *
	 * @param string $method HTTP method.
	 * @param string $path   Path.
	 * @param string $access Access level.
	 */
	private function operation( string $method, string $path, string $access ): RouteOperation {
		return new RouteOperation( $method, $path, array(), array(), array(), $access );
	}
}
