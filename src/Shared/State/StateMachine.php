<?php
/**
 * Lifecycles as states and allowed transitions (DRY-2, VEN-2).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Shared\State;

use InvalidArgumentException;
use Mercatus\Core\Shared\Errors\DomainError;

/**
 * Checked before any write, so a refused transition never reaches the database. Vendor applications and
 * vendors are the first users; vendor orders and withdrawals follow.
 */
final readonly class StateMachine {

	/**
	 * Creates the machine.
	 *
	 * @param string                      $name        Lifecycle name, for errors.
	 * @param array<string, list<string>> $transitions The states each state may move to; every state is a key.
	 * @throws InvalidArgumentException When a transition leads to an undeclared state.
	 */
	public function __construct( public string $name, public array $transitions ) {
		foreach ( $transitions as $targets ) {
			foreach ( $targets as $target ) {
				if ( ! isset( $transitions[ $target ] ) ) {
					throw new InvalidArgumentException( 'Every target state must be declared.' );
				}
			}
		}
	}

	/**
	 * Whether a state exists.
	 *
	 * @param string $state State.
	 */
	public function has( string $state ): bool {
		return isset( $this->transitions[ $state ] );
	}

	/**
	 * Whether a transition is allowed.
	 *
	 * @param string $from Current state.
	 * @param string $to   Next state.
	 */
	public function allows( string $from, string $to ): bool {
		return in_array( $to, $this->transitions[ $from ] ?? array(), true );
	}

	/**
	 * Refuses a transition that isn't allowed.
	 *
	 * @param string $from Current state.
	 * @param string $to   Next state.
	 * @throws DomainError When it isn't allowed (mercatus_transition_not_allowed).
	 */
	public function assert( string $from, string $to ): void {
		if ( ! $this->allows( $from, $to ) ) {
			throw DomainError::of(
				'mercatus_transition_not_allowed',
				array(
					'lifecycle' => $this->name,
					'from'      => $from,
					'to'        => $to,
				)
			);
		}
	}
}
