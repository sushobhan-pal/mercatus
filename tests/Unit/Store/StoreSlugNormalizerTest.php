<?php
/**
 * Store slugs (STO-9).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Unit\Store;

use Mercatus\Core\Shared\Errors\DomainError;
use Mercatus\Core\Store\Domain\StoreSlugNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * Names become lower-case ASCII slugs; reserved, too short or all-digit ones are prefixed; a taken one gets a
 * number; a slug someone chose must already be in that form.
 */
final class StoreSlugNormalizerTest extends TestCase {

	/**
	 * Names and the slugs made from them.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function names(): array {
		return array(
			'plain'        => array( 'Rare Maps', 'rare-maps' ),
			'accents'      => array( 'Café Crème', 'cafe-creme' ),
			'punctuation'  => array( "  Bob's   Books & Prints!! ", 'bob-s-books-prints' ),
			'reserved'     => array( 'Dashboard', 'store-dashboard' ),
			'all digits'   => array( '2024', 'store-2024' ),
			'too short'    => array( 'Ab', 'store-ab' ),
			'nothing kept' => array( '***', 'store-1' ),
		);
	}

	/**
	 * Makes a slug from a name.
	 *
	 * @dataProvider names
	 * @param string $name Name.
	 * @param string $slug Expected slug.
	 */
	public function testMakesASlugFromAName( string $name, string $slug ): void {
		$this->assertSame( $slug, StoreSlugNormalizer::fromName( $name ) );
	}

	public function testAvoidsTheExtraReservedSlugs(): void {
		$this->assertSame( 'store-marketplace-dashboard', StoreSlugNormalizer::fromName( 'Marketplace dashboard', array( 'marketplace-dashboard' ) ) );
	}

	public function testATakenSlugGetsANumberWithinTheLimit(): void {
		$this->assertSame( 'rare-maps', StoreSlugNormalizer::candidate( 'rare-maps', 1 ) );
		$this->assertSame( 'rare-maps-2', StoreSlugNormalizer::candidate( 'rare-maps', 2 ) );
		$long = str_repeat( 'a', StoreSlugNormalizer::MAX );
		$this->assertSame( StoreSlugNormalizer::MAX, strlen( StoreSlugNormalizer::candidate( $long, 12 ) ) );
		$this->assertStringEndsWith( '-12', StoreSlugNormalizer::candidate( $long, 12 ) );
	}

	public function testANameLongerThanTheLimitIsCutWithoutATrailingHyphen(): void {
		$slug = StoreSlugNormalizer::fromName( str_repeat( 'word ', 40 ) );

		$this->assertLessThanOrEqual( StoreSlugNormalizer::MAX, strlen( $slug ) );
		$this->assertStringEndsNotWith( '-', $slug );
		StoreSlugNormalizer::assertValid( $slug );
	}

	/**
	 * Slugs someone may choose.
	 *
	 * @return array<string, array{string}>
	 */
	public static function validSlugs(): array {
		return array(
			'words'        => array( 'rare-maps' ),
			'digits mixed' => array( 'shop-24' ),
			'shortest'     => array( 'abc' ),
		);
	}

	/**
	 * Accepts a valid chosen slug.
	 *
	 * @dataProvider validSlugs
	 * @param string $slug Slug.
	 */
	public function testAcceptsAValidChosenSlug( string $slug ): void {
		StoreSlugNormalizer::assertValid( $slug );
		$this->addToAssertionCount( 1 );
	}

	/**
	 * Slugs nobody may choose.
	 *
	 * @return array<string, array{string}>
	 */
	public static function invalidSlugs(): array {
		return array(
			'upper case'  => array( 'Rare-Maps' ),
			'spaces'      => array( 'rare maps' ),
			'double dash' => array( 'rare--maps' ),
			'edge dash'   => array( '-rare' ),
			'too short'   => array( 'ab' ),
			'too long'    => array( str_repeat( 'a', StoreSlugNormalizer::MAX + 1 ) ),
			'all digits'  => array( '12345' ),
			'digit dash'  => array( '12-34' ),
			'reserved'    => array( 'wp-admin' ),
		);
	}

	/**
	 * Refuses an invalid chosen slug.
	 *
	 * @dataProvider invalidSlugs
	 * @param string $slug Slug.
	 */
	public function testRefusesAnInvalidChosenSlug( string $slug ): void {
		$this->expectException( DomainError::class );

		StoreSlugNormalizer::assertValid( $slug );
	}
}
