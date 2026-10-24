<?php
/**
 * The stores table and its queries (DATA-2, TEST-1 Layer 4).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Integration\Store;

use Mercatus\Core\Shared\Http\ListRequest;
use Mercatus\Core\Store\Infrastructure\StoreRepository;
use WP_UnitTestCase;

/**
 * Pages come in name order with a cursor that never repeats or skips a store, a name prefix narrows them, and
 * one store with its default location is a single lookup on a unique key.
 */
final class StoreRepositoryTest extends WP_UnitTestCase {

	use StoreTestHelpers;

	public function testPagesFollowTheNameAndTheCursor(): void {
		foreach ( array( 'Delta Tools', 'Alpha Books', 'Charlie Tea', 'Bravo Maps' ) as $name ) {
			$this->vendorWithStore( $name );
		}
		$repository = self::kernel()->container()->get( StoreRepository::class );

		$first = $repository->page( new ListRequest( 'name', 'asc', 2, null, null, array() ), '' );
		$this->assertSame( array( 'Alpha Books', 'Bravo Maps', 'Charlie Tea' ), array_map( static fn( array $pair ): string => $pair[0]->name, $first ), 'Two plus one to tell more follow.' );

		$second = $repository->page( new ListRequest( 'name', 'asc', 2, 'Bravo Maps', $first[1][0]->id, array() ), '' );
		$this->assertSame( array( 'Charlie Tea', 'Delta Tools' ), array_map( static fn( array $pair ): string => $pair[0]->name, $second ) );

		$found = $repository->page( new ListRequest( 'name', 'asc', 10, null, null, array() ), 'Ch' );
		$this->assertSame( array( 'Charlie Tea' ), array_map( static fn( array $pair ): string => $pair[0]->name, $found ) );
		$this->assertNotNull( $found[0][1], 'With its default location.' );
	}

	public function testOneStoreIsAUniqueKeyLookup(): void {
		global $wpdb;
		$vendor     = $this->vendorWithStore();
		$repository = self::kernel()->container()->get( StoreRepository::class );

		foreach ( array(
			'slug'      => fn() => $repository->bySlug( $vendor['slug'] ),
			'vendor_id' => fn() => $repository->byVendorId( $vendor['vendor_id'] ),
		) as $key => $lookup ) {
			$this->assertNotNull( $lookup() );
			$plan = $wpdb->get_results( 'EXPLAIN ' . $wpdb->last_query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- explaining the query just run.
			$this->assertIsArray( $plan );
			$this->assertSame( array( 'const', $key ), array( $plan[0]['type'], $plan[0]['key'] ), (string) wp_json_encode( $plan ) );
		}
	}
}
