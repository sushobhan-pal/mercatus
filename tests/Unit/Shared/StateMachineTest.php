<?php
/**
 * Lifecycles allow only their declared transitions (DRY-2, VEN-2).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Unit\Shared;

use InvalidArgumentException;
use Mercatus\Core\Shared\Errors\DomainError;
use Mercatus\Core\Shared\State\StateMachine;
use PHPUnit\Framework\TestCase;

/**
 * Allowed moves pass, anything else is refused with mercatus_transition_not_allowed, and a definition can't
 * lead to an undeclared state.
 */
final class StateMachineTest extends TestCase {

	private function machine(): StateMachine {
		return new StateMachine(
			'probe',
			array(
				'draft'     => array( 'published' ),
				'published' => array( 'draft', 'archived' ),
				'archived'  => array(),
			)
		);
	}

	public function testAllowsOnlyDeclaredTransitions(): void {
		$machine = $this->machine();

		$this->assertTrue( $machine->allows( 'draft', 'published' ) );
		$this->assertFalse( $machine->allows( 'draft', 'archived' ) );
		$this->assertFalse( $machine->allows( 'archived', 'draft' ) );
		$this->assertFalse( $machine->allows( 'unknown', 'draft' ) );
		$this->assertTrue( $machine->has( 'archived' ) );
	}

	public function testRefusesWithTheLifecycleAndStates(): void {
		try {
			$this->machine()->assert( 'archived', 'published' );
			$this->fail( 'Expected a refusal.' );
		} catch ( DomainError $error ) {
			$this->assertSame(
				array(
					'mercatus_transition_not_allowed',
					array(
						'lifecycle' => 'probe',
						'from'      => 'archived',
						'to'        => 'published',
					),
				),
				array( $error->errorCode, $error->details )
			);
		}
	}

	public function testRefusesATransitionToAnUndeclaredState(): void {
		$this->expectException( InvalidArgumentException::class );

		new StateMachine( 'broken', array( 'a' => array( 'b' ) ) );
	}
}
