<?php
/**
 * Conformance tests every CatalogSearchPort passes (TEST-1 Layer 3, CAT-4, CAT-5).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Contract\Conformance;

use Mercatus\Core\Contracts\Catalog\CatalogQuery;
use Mercatus\Core\Contracts\Catalog\CatalogSearchPort;

/**
 * Use in a TestCase and implement createCatalogSearchPort(). The trait indexes the products itself.
 */
trait CatalogSearchPortConformanceTrait {

	private const COLOUR = 10;
	private const RED    = 101;
	private const BLUE   = 102;
	private const SIZE   = 20;
	private const SMALL  = 201;
	private const LARGE  = 202;

	/**
	 * Returns a port whose backing data holds these catalog products, none of them indexed yet.
	 *
	 * @param array<int, array{title: string, category_id: int, attributes: array<int, list<int>>}> $products Products by catalog product ID.
	 */
	abstract protected function createCatalogSearchPort( array $products ): CatalogSearchPort;

	public function testCatalogSearchPortFindsByText(): void {
		$this->assertSame( array( 1, 4 ), $this->search( new CatalogQuery( text: 'mug' ) ) );
	}

	public function testCatalogSearchPortFiltersByCategory(): void {
		$this->assertSame( array( 3, 5 ), $this->search( new CatalogQuery( categoryId: 2 ) ) );
	}

	public function testCatalogSearchPortMatchesAnyValueOfOneAttributeAndEveryAttribute(): void {
		$this->assertSame(
			array( 1, 2, 3, 4 ),
			$this->search( new CatalogQuery( attributeValues: array( self::COLOUR => array( self::RED, self::BLUE ) ) ) )
		);
		$this->assertSame(
			array( 1, 3 ),
			$this->search(
				new CatalogQuery(
					attributeValues: array(
						self::COLOUR => array( self::RED, self::BLUE ),
						self::SIZE   => array( self::SMALL ),
					)
				)
			)
		);
	}

	public function testCatalogSearchPortPagesThroughEveryMatchOnce(): void {
		$port   = $this->indexedPort();
		$seen   = array();
		$cursor = null;
		$pages  = 0;

		do {
			$page = $port->search( new CatalogQuery( cursor: $cursor, perPage: 2 ) );
			$this->assertLessThanOrEqual( 2, count( $page->catalogProductIds ) );
			$seen   = array_merge( $seen, $page->catalogProductIds );
			$cursor = $page->nextCursor;
			++$pages;
		} while ( null !== $cursor && $pages < 10 );

		sort( $seen );
		$this->assertSame( array( 1, 2, 3, 4, 5 ), $seen );
		$this->assertSame( 3, $pages );
	}

	public function testCatalogSearchPortForgetsRemovedProducts(): void {
		$port = $this->indexedPort();

		$port->remove( array( 1, 999 ) );

		$this->assertSame( array( 4 ), $this->ids( $port->search( new CatalogQuery( text: 'mug' ) )->catalogProductIds ) );
	}

	public function testCatalogSearchPortCountsFacetsOfTheMatches(): void {
		$result = $this->indexedPort()->search( new CatalogQuery( categoryId: 1 ) );
		$counts = $result->facetCounts;

		if ( $result->facetCountsApproximate ) {
			array_walk_recursive( $counts, fn( int $count ) => $this->assertGreaterThanOrEqual( 0, $count ) );
			return;
		}

		$this->assertSame( 2, $counts[ self::COLOUR ][ self::RED ] ?? null );
		$this->assertSame( 1, $counts[ self::COLOUR ][ self::BLUE ] ?? null );
		$this->assertSame( 1, $counts[ self::SIZE ][ self::SMALL ] ?? null );
		$this->assertSame( 1, $counts[ self::SIZE ][ self::LARGE ] ?? null );
	}

	/**
	 * Runs one query against a freshly indexed port and returns the matches in ID order.
	 *
	 * @param CatalogQuery $query Query to run.
	 * @return list<int>
	 */
	private function search( CatalogQuery $query ): array {
		return $this->ids( $this->indexedPort()->search( $query )->catalogProductIds );
	}

	/**
	 * Sorts IDs, since result order is the port's own choice.
	 *
	 * @param list<int> $ids Catalog product IDs.
	 * @return list<int>
	 */
	private function ids( array $ids ): array {
		sort( $ids );
		return $ids;
	}

	/**
	 * Five products in two categories, indexed.
	 */
	private function indexedPort(): CatalogSearchPort {
		$products = array(
			1 => array(
				'title'       => 'Red ceramic mug',
				'category_id' => 1,
				'attributes'  => array(
					self::COLOUR => array( self::RED ),
					self::SIZE   => array( self::SMALL ),
				),
			),
			2 => array(
				'title'       => 'Blue teapot',
				'category_id' => 1,
				'attributes'  => array(
					self::COLOUR => array( self::BLUE ),
					self::SIZE   => array( self::LARGE ),
				),
			),
			3 => array(
				'title'       => 'Red cotton scarf',
				'category_id' => 2,
				'attributes'  => array(
					self::COLOUR => array( self::RED ),
					self::SIZE   => array( self::SMALL ),
				),
			),
			4 => array(
				'title'       => 'Travel mug',
				'category_id' => 1,
				'attributes'  => array( self::COLOUR => array( self::RED ) ),
			),
			5 => array(
				'title'       => 'Wool hat',
				'category_id' => 2,
				'attributes'  => array(),
			),
		);

		$port = $this->createCatalogSearchPort( $products );
		$port->index( array_keys( $products ) );

		return $port;
	}
}
