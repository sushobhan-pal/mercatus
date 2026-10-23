<?php
/**
 * Seed steps get repeatable randomness, sizes and batches (KER-12).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Unit\Seeding;

use Mercatus\Core\Database\Seeders\SeedContext;
use PHPUnit\Framework\TestCase;

/**
 * Same seed and module, same data; another module's sequence is its own; sizes follow PLAT-10 at a scale.
 */
final class SeedContextTest extends TestCase {

	public function testEachModuleGetsItsOwnRepeatableSequence(): void {
		$this->assertSame( $this->draws( 'core.vendor', 7 ), $this->draws( 'core.vendor', 7 ) );
		$this->assertNotSame( $this->draws( 'core.vendor', 7 ), $this->draws( 'core.vendor', 8 ) );
		$this->assertNotSame( $this->draws( 'core.vendor', 7 ), $this->draws( 'core.catalog', 7 ) );
	}

	public function testScalesTheDataSetButNeverBelowOne(): void {
		$this->assertSame( 100000, $this->context( 'core.catalog', 1 )->count( 'products' ) );
		$this->assertSame( 1000, $this->context( 'core.catalog', 1, 0.01 )->count( 'products' ) );
		$this->assertSame( 1, $this->context( 'core.vendor', 1, 0.00001 )->count( 'vendors' ) );
	}

	public function testWorksThroughATotalInBatchesAndFreesMemoryBetweenThem(): void {
		$batches = array();
		$freed   = 0;
		$context = new SeedContext(
			'core.catalog',
			1,
			1.0,
			static fn(): null => null,
			static function () use ( &$freed ): void {
				++$freed;
			}
		);

		$context->inBatches(
			10,
			4,
			static function ( int $offset, int $size ) use ( &$batches ): void {
				$batches[] = array( $offset, $size );
			}
		);

		$this->assertSame( array( array( 0, 4 ), array( 4, 4 ), array( 8, 2 ) ), $batches );
		$this->assertSame( 2, $freed );
	}

	/**
	 * A context that prints nothing.
	 *
	 * @param string $module_id Module ID.
	 * @param int    $seed      Seed.
	 * @param float  $scale     Scale.
	 */
	private function context( string $module_id, int $seed, float $scale = 1.0 ): SeedContext {
		return new SeedContext( $module_id, $seed, $scale, static fn(): null => null, static fn(): null => null );
	}

	/**
	 * The first numbers of a module's sequence.
	 *
	 * @param string $module_id Module ID.
	 * @param int    $seed      Seed.
	 * @return list<int>
	 */
	private function draws( string $module_id, int $seed ): array {
		$random = $this->context( $module_id, $seed )->random;

		return array_map( static fn(): int => $random->getInt( 0, PHP_INT_MAX ), range( 1, 5 ) );
	}
}
