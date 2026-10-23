<?php
/**
 * The scale seeder runs each active module's step with a fixed seed (KER-12, PLAT-10).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Integration\Seeding;

use Mercatus\Core\Database\Seeders\ScaleBenchmarkSeeder;
use Mercatus\Core\Tests\Fixtures\Seeders\RecordingSeedStep;
use UnexpectedValueException;
use WP_UnitTestCase;

/**
 * Steps run in load order for active modules only, the run is recorded, and the same seed gives the same data.
 */
final class ScaleBenchmarkSeederTest extends WP_UnitTestCase {

	private const STEPS = __DIR__ . '/../../Fixtures/Seeders/steps';

	public function set_up(): void {
		parent::set_up();
		RecordingSeedStep::$runs = array();
	}

	public function testRunsTheStepsOfActiveModulesInLoadOrder(): void {
		$seeded = $this->seed( array( 'pro.alpha', 'core.kernel', 'pro.without-step' ), 7 );

		$this->assertSame( array( 'pro.alpha', 'core.kernel' ), $seeded );
		$this->assertSame( array( 'pro.alpha', 'core.kernel' ), array_keys( RecordingSeedStep::$runs ) );
		$record = get_option( ScaleBenchmarkSeeder::OPTION );
		$this->assertIsArray( $record );
		$this->assertSame( array( 7, 0.5, array( 'pro.alpha', 'core.kernel' ) ), array( $record['seed'], $record['scale'], $record['modules'] ) );
	}

	public function testTheSameSeedCreatesTheSameData(): void {
		$this->seed( array( 'pro.alpha' ), 7 );
		$first = RecordingSeedStep::$runs;
		$this->seed( array( 'pro.alpha' ), 7 );
		$again = RecordingSeedStep::$runs;
		$this->seed( array( 'pro.alpha' ), 8 );

		$this->assertSame( $first, $again );
		$this->assertNotSame( $first, RecordingSeedStep::$runs );
	}

	public function testRefusesAStepFileThatReturnsSomethingElse(): void {
		$this->expectException( UnexpectedValueException::class );

		$this->seed( array( 'pro.broken' ), 7 );
	}

	/**
	 * Runs the seeder over the fixture steps.
	 *
	 * @param list<string> $modules Active module IDs, in load order.
	 * @param int          $seed    Seed.
	 * @return list<string>
	 */
	private function seed( array $modules, int $seed ): array {
		return ( new ScaleBenchmarkSeeder( $modules, self::STEPS ) )->run( $seed, 0.5, static fn(): null => null );
	}
}
