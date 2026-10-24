<?php
/**
 * Only allow-listed sorts, filters and cursors reach a query (TM-35, SEC-3, G-SEC-2).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Unit\Http;

use Mercatus\Core\Shared\Errors\DomainError;
use Mercatus\Core\Shared\Http\Cursor;
use Mercatus\Core\Shared\Http\ListDefinition;
use PHPUnit\Framework\TestCase;

/**
 * Anything outside the list's allow-list, injection attempts included, is refused with
 * mercatus_invalid_list_parameter naming the parameter.
 */
final class SortAndFilterAllowListTest extends TestCase {

	private function definition(): ListDefinition {
		return new ListDefinition(
			array(
				'id'   => 'int',
				'name' => 'string',
			),
			array( 'status' => array( 'active', 'revoked' ) ),
			24,
			100
		);
	}

	public function testDefaultsToTheFirstSortAscending(): void {
		$list = $this->definition()->parse( array() );

		$this->assertSame( array( 'id', 'asc', 24, null, null, array() ), array( $list->sort, $list->direction, $list->perPage, $list->afterValue, $list->afterId, $list->filters ) );
	}

	public function testAcceptsAllowedValuesAndItsOwnCursor(): void {
		$list = $this->definition()->parse(
			array(
				'orderby'  => 'name',
				'order'    => 'desc',
				'per_page' => '10',
				'status'   => 'revoked',
				'cursor'   => Cursor::encode( 'name', 'desc', 'Mobile app', 7 ),
			)
		);

		$this->assertSame( array( 'name', 'desc', 10, 'Mobile app', 7, array( 'status' => 'revoked' ) ), array( $list->sort, $list->direction, $list->perPage, $list->afterValue, $list->afterId, $list->filters ) );
	}

	/**
	 * Parameters outside the allow-list, and the parameter each error names.
	 *
	 * @return array<string, array{array<string, mixed>, string}>
	 */
	public static function refused(): array {
		return array(
			'unknown sort column'      => array( array( 'orderby' => 'password' ), 'orderby' ),
			'injection in orderby'     => array( array( 'orderby' => 'id; DROP TABLE wp_users' ), 'orderby' ),
			'injection in order'       => array( array( 'order' => 'asc, (SELECT 1)' ), 'order' ),
			'array orderby'            => array( array( 'orderby' => array( 'id' ) ), 'orderby' ),
			'zero per page'            => array( array( 'per_page' => 0 ), 'per_page' ),
			'too many per page'        => array( array( 'per_page' => 1000 ), 'per_page' ),
			'unknown filter value'     => array( array( 'status' => "active' OR '1'='1" ), 'status' ),
			'malformed cursor'         => array( array( 'cursor' => 'abc$' ), 'cursor' ),
			'cursor for another sort'  => array( array( 'cursor' => Cursor::encode( 'name', 'asc', 'x', 1 ) ), 'cursor' ),
			'cursor for another order' => array( array( 'cursor' => Cursor::encode( 'id', 'desc', 5, 5 ) ), 'cursor' ),
			'cursor of the wrong type' => array( array( 'cursor' => Cursor::encode( 'id', 'asc', '5 OR 1=1', 5 ) ), 'cursor' ),
		);
	}

	/**
	 * Refuses parameters outside the allow-list.
	 *
	 * @dataProvider refused
	 * @param array<string, mixed> $params    Parameters.
	 * @param string               $parameter The parameter the error names.
	 */
	public function testRefusesWhatTheAllowListDoesNotName( array $params, string $parameter ): void {
		try {
			$this->definition()->parse( $params );
			$this->fail( 'Expected a refusal.' );
		} catch ( DomainError $error ) {
			$this->assertSame( array( 'mercatus_invalid_list_parameter', array( 'parameter' => $parameter ) ), array( $error->errorCode, $error->details ) );
		}
	}

	public function testDescribesOnlyTheAllowedValuesAsRouteArguments(): void {
		$args = $this->definition()->args();

		$this->assertSame( array( 'id', 'name' ), $args['orderby']['enum'] );
		$this->assertSame( array( 'active', 'revoked' ), $args['status']['enum'] );
		$this->assertSame( 100, $args['per_page']['maximum'] );
	}
}
