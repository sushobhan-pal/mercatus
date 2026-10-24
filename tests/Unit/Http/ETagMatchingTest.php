<?php
/**
 * ETags and the headers that name them (PLAT-9, IDN-8).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Unit\Http;

use Mercatus\Core\Shared\Http\ETag;
use PHPUnit\Framework\TestCase;

/**
 * Versions give stable ETags, list ETags change with any item's version, and If-None-Match and If-Match are
 * read the way HTTP defines them.
 */
final class ETagMatchingTest extends TestCase {

	public function testAnItemsETagIsItsVersion(): void {
		$this->assertSame( '"v3"', ETag::forVersion( 3 ) );
		$this->assertSame( 3, ETag::version( ' "v3" ' ) );
		$this->assertNull( ETag::version( 'W/"v3"' ) );
		$this->assertNull( ETag::version( null ) );
	}

	public function testAListsETagChangesWithAnyItemsVersion(): void {
		$page = ETag::forList( array( array( 1, 1 ), array( 2, 5 ) ) );

		$this->assertSame( $page, ETag::forList( array( array( 1, 1 ), array( 2, 5 ) ) ) );
		$this->assertNotSame( $page, ETag::forList( array( array( 1, 1 ), array( 2, 6 ) ) ) );
		$this->assertNotSame( $page, ETag::forList( array( array( 2, 5 ), array( 1, 1 ) ) ) );
	}

	public function testMatchesTheWayIfNoneMatchDoes(): void {
		$this->assertTrue( ETag::matches( '"v3"', '"v3"' ) );
		$this->assertTrue( ETag::matches( 'W/"v3"', '"v3"' ) );
		$this->assertTrue( ETag::matches( '"v1", "v3"', '"v3"' ) );
		$this->assertTrue( ETag::matches( '*', '"v3"' ) );
		$this->assertFalse( ETag::matches( '"v4"', '"v3"' ) );
		$this->assertFalse( ETag::matches( '', '"v3"' ) );
		$this->assertFalse( ETag::matches( null, '"v3"' ) );
	}
}
