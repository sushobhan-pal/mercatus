<?php
/**
 * Conformance tests every CachePort adapter passes (TEST-1 Layer 3).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Contract\Conformance;

use InvalidArgumentException;
use Mercatus\Core\Contracts\Cache\CachePort;

/**
 * Use in a TestCase and implement createCachePort(). Each call must return an empty cache.
 */
trait CachePortConformanceTrait {

	/**
	 * Returns an empty cache.
	 */
	abstract protected function createCachePort(): CachePort;

	public function testCachePortReturnsNullOnMiss(): void {
		$this->assertNull( $this->createCachePort()->get( 'mercatus_tests', 'missing' ) );
	}

	public function testCachePortReturnsWhatWasStored(): void {
		$cache = $this->createCachePort();
		$value = array(
			'id'    => 42,
			'title' => 'Mug',
		);

		$cache->set( 'mercatus_tests', 'product', $value, 60 );
		$cache->set( 'mercatus_tests', 'zero', 0, 60 );
		$cache->set( 'mercatus_tests', 'false', false, 60 );

		$this->assertSame( $value, $cache->get( 'mercatus_tests', 'product' ) );
		$this->assertSame( 0, $cache->get( 'mercatus_tests', 'zero' ) );
		$this->assertFalse( $cache->get( 'mercatus_tests', 'false' ) );
	}

	public function testCachePortKeepsGroupsApart(): void {
		$cache = $this->createCachePort();

		$cache->set( 'mercatus_tests', 'key', 'first', 60 );
		$cache->set( 'mercatus_tests_other', 'key', 'second', 60 );

		$this->assertSame( 'first', $cache->get( 'mercatus_tests', 'key' ) );
		$this->assertSame( 'second', $cache->get( 'mercatus_tests_other', 'key' ) );
	}

	public function testCachePortGetsAndSetsManyKeys(): void {
		$cache = $this->createCachePort();

		$cache->setMany(
			'mercatus_tests',
			array(
				'a' => 1,
				'b' => 2,
			),
			60
		);

		$found = $cache->getMany( 'mercatus_tests', array( 'a', 'b', 'missing' ) );
		ksort( $found );
		$this->assertSame(
			array(
				'a' => 1,
				'b' => 2,
			),
			$found
		);
		$this->assertSame( array(), $cache->getMany( 'mercatus_tests', array() ) );
	}

	public function testCachePortDeletesAValue(): void {
		$cache = $this->createCachePort();
		$cache->set( 'mercatus_tests', 'key', 'value', 60 );

		$cache->delete( 'mercatus_tests', 'key' );
		$cache->delete( 'mercatus_tests', 'never-set' );

		$this->assertNull( $cache->get( 'mercatus_tests', 'key' ) );
	}

	public function testCachePortRefusesNull(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->createCachePort()->set( 'mercatus_tests', 'key', null, 60 );
	}

	public function testCachePortRefusesNullInSetMany(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->createCachePort()->setMany( 'mercatus_tests', array( 'key' => null ), 60 );
	}

	public function testCachePortRefusesATtlBelowOne(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->createCachePort()->set( 'mercatus_tests', 'key', 'value', 0 );
	}

	public function testCachePortRemembersAComputedValue(): void {
		$cache = $this->createCachePort();
		$calls = 0;
		$build = static function () use ( &$calls ): string {
			++$calls;
			return 'built-' . $calls;
		};

		$this->assertSame( 'built-1', $cache->remember( 'mercatus_tests', 'key', 60, $build ) );
		$this->assertSame( 'built-1', $cache->remember( 'mercatus_tests', 'key', 60, $build ) );
		$this->assertSame( 1, $calls );
		$this->assertSame( 'built-1', $cache->get( 'mercatus_tests', 'key' ) );
	}

	public function testCachePortRefusesToRememberNull(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->createCachePort()->remember( 'mercatus_tests', 'key', 60, static fn() => null );
	}

	public function testCachePortSeedsRevisionsFromTheClock(): void {
		$before   = time();
		$revision = $this->createCachePort()->revision( 'product:42' );

		$this->assertGreaterThanOrEqual( $before, $revision );
	}

	public function testCachePortRevisionIsStableUntilBumped(): void {
		$cache = $this->createCachePort();
		$first = $cache->revision( 'product:42' );

		$this->assertSame( $first, $cache->revision( 'product:42' ) );

		$bumped = $cache->bumpRevision( 'product:42' );

		$this->assertGreaterThan( $first, $bumped );
		$this->assertSame( $bumped, $cache->revision( 'product:42' ) );
	}

	public function testCachePortBumpsAnUnseenScopeFromTheClock(): void {
		$before = time();

		$this->assertGreaterThanOrEqual( $before, $this->createCachePort()->bumpRevision( 'product:7' ) );
	}

	public function testCachePortFlushGroupHidesOnlyThatGroup(): void {
		$cache = $this->createCachePort();
		$cache->set( 'mercatus_tests', 'key', 'old', 60 );
		$cache->set( 'mercatus_tests_other', 'key', 'kept', 60 );

		$cache->flushGroup( 'mercatus_tests' );

		$this->assertNull( $cache->get( 'mercatus_tests', 'key' ) );
		$this->assertSame( array(), $cache->getMany( 'mercatus_tests', array( 'key' ) ) );
		$this->assertSame( 'kept', $cache->get( 'mercatus_tests_other', 'key' ) );

		$cache->set( 'mercatus_tests', 'key', 'new', 60 );
		$this->assertSame( 'new', $cache->get( 'mercatus_tests', 'key' ) );
	}
}
