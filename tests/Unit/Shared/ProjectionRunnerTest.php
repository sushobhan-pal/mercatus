<?php
/**
 * The projection runner writes only what changed (DRY-2, CAT-5).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Unit\Shared;

use Mercatus\Core\Shared\Projection\ProjectionRunner;
use Mercatus\Core\Tests\Contract\Fakes\InMemoryCachePort;
use Mercatus\Core\Tests\Fixtures\Projection\RecordingProjector;
use PHPUnit\Framework\TestCase;

/**
 * A rebuild compares the rows a key should have with the rows it has, by row key and content, then writes the
 * difference and bumps the key's revision; a rebuild that finds nothing new writes nothing.
 */
final class ProjectionRunnerTest extends TestCase {

	public function testWritesOnlyTheRowsThatChanged(): void {
		$projector = self::projector(
			array(
				'a' => array( 'price' => '10.000000' ),
				'b' => array( 'price' => '12.000000' ),
				'c' => array( 'price' => '1.000000' ),
			),
			array(
				'a' => array( 'price' => '10.000000' ),
				'b' => array( 'price' => '11.000000' ),
				'd' => array( 'price' => '2.000000' ),
			)
		);
		$cache     = new InMemoryCachePort();
		$before    = $cache->revision( 'p:7' );

		$this->assertTrue( ( new ProjectionRunner( $cache ) )->rebuild( $projector, 7 ) );
		$this->assertSame( array( 'b', 'd' ), array_keys( $projector->upserts ?? array() ), 'Changed and new rows.' );
		$this->assertSame( array( 'c' ), $projector->deletes, 'Rows the key no longer has.' );
		$this->assertNotSame( $before, $cache->revision( 'p:7' ) );
	}

	public function testANothingNewRebuildWritesNothing(): void {
		$rows      = array(
			'a' => array(
				'price' => '10.000000',
				'name'  => 'Map',
			),
		);
		$projector = self::projector(
			$rows,
			array(
				'a' => array(
					'name'  => 'Map',
					'price' => '10.000000',
				),
			)
		);
		$cache     = new InMemoryCachePort();
		$before    = $cache->revision( 'p:7' );

		$this->assertFalse( ( new ProjectionRunner( $cache ) )->rebuild( $projector, 7 ), 'Column order doesn\'t matter.' );
		$this->assertNull( $projector->upserts );
		$this->assertSame( $before, $cache->revision( 'p:7' ) );
	}

	public function testInsideATransactionTheCallerBumpsTheRevision(): void {
		$projector = self::projector( array(), array( 'a' => array( 'price' => '1.000000' ) ) );
		$cache     = new InMemoryCachePort();
		$before    = $cache->revision( 'p:7' );

		$this->assertTrue( ( new ProjectionRunner( $cache ) )->rebuild( $projector, 7, false ) );
		$this->assertSame( $before, $cache->revision( 'p:7' ) );
	}

	/**
	 * A projector over fixed rows.
	 *
	 * @param array<string, array<string, string|null>> $stored  Rows it has.
	 * @param array<string, array<string, string|null>> $desired Rows it should have.
	 */
	private static function projector( array $stored, array $desired ): RecordingProjector {
		return new RecordingProjector( $stored, $desired );
	}
}
