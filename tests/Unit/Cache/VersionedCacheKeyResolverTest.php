<?php
/**
 * Cache keys with revision pointers (PLAT-5).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Unit\Cache;

use InvalidArgumentException;
use Mercatus\Core\Shared\Cache\Revision;
use Mercatus\Core\Shared\Cache\VersionedCacheKeyResolver;
use Mercatus\Core\Tests\Contract\Fakes\InMemoryCachePort;
use PHPUnit\Framework\TestCase;

/**
 * Keys follow their revision scopes, stay stable otherwise, and stay safe for every backend.
 */
final class VersionedCacheKeyResolverTest extends TestCase {

	private InMemoryCachePort $cache;

	private VersionedCacheKeyResolver $resolver;

	protected function setUp(): void {
		$this->cache    = new InMemoryCachePort();
		$this->resolver = new VersionedCacheKeyResolver( $this->cache );
	}

	public function testPutsTheScopesRevisionInPlaceOfItsMarker(): void {
		$revision = $this->cache->revision( 'product:42' );

		$this->assertSame( 'p:42:v:' . $revision . ':loc:3', $this->availabilityKey( 42 ) );
		$this->assertSame( $this->availabilityKey( 42 ), $this->availabilityKey( 42 ) );
	}

	public function testChangesOnlyTheKeysOfABumpedScope(): void {
		$before_42 = $this->availabilityKey( 42 );
		$before_7  = $this->availabilityKey( 7 );

		$this->cache->bumpRevision( 'product:42' );

		$this->assertNotSame( $before_42, $this->availabilityKey( 42 ) );
		$this->assertSame( $before_7, $this->availabilityKey( 7 ) );
	}

	public function testHashesUnsafeOrLongKeys(): void {
		$unsafe = $this->resolver->resolve( array( 'q' => 'red mug' ) );
		$long   = $this->resolver->resolve( array( 'q' => str_repeat( 'a', 200 ) ) );

		$this->assertMatchesRegularExpression( '/^h:[0-9a-f]{32}$/', $unsafe );
		$this->assertMatchesRegularExpression( '/^h:[0-9a-f]{32}$/', $long );
		$this->assertNotSame( $unsafe, $this->resolver->resolve( array( 'q' => 'blue mug' ) ) );
	}

	/**
	 * Parts that are refused.
	 *
	 * @return array<string, array{array<string, int|string>}>
	 */
	public static function invalidParts(): array {
		return array(
			'no parts'          => array( array() ),
			'upper-case label'  => array( array( 'Product' => 1 ) ),
			'label with spaces' => array( array( 'pro duct' => 1 ) ),
		);
	}

	/**
	 * Refuses parts that would make an unclear key.
	 *
	 * @dataProvider invalidParts
	 * @param array<string, int|string> $parts Parts.
	 */
	public function testRefusesUnclearParts( array $parts ): void {
		$this->expectException( InvalidArgumentException::class );
		$this->resolver->resolve( $parts );
	}

	public function testRefusesAnEmptyScope(): void {
		$this->expectException( InvalidArgumentException::class );
		Revision::of( '' );
	}

	/**
	 * The PLAT-5 example key for a product at location 3.
	 *
	 * @param int $product_id Product ID.
	 */
	private function availabilityKey( int $product_id ): string {
		return $this->resolver->resolve(
			array(
				'p'   => $product_id,
				'v'   => Revision::of( 'product:' . $product_id ),
				'loc' => 3,
			)
		);
	}
}
