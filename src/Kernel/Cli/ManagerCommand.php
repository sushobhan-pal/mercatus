<?php
/**
 * WP-CLI commands for marketplace managers (IDN-15).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Kernel\Cli;

use Mercatus\Core\Identity\Infrastructure\ManagerAppointments;
use Mercatus\Core\Kernel\Kernel;
use Mercatus\Core\Shared\Errors\DomainError;

/**
 * `wp mercatus manager appoint <user>`, `remove <user>` and `list`. The dashboard screen arrives with the shell
 * in core.vendor.
 */
final class ManagerCommand {

	/**
	 * Creates the command.
	 *
	 * @param Kernel $kernel Booted kernel.
	 */
	public function __construct( private Kernel $kernel ) {}

	/**
	 * Appoints a marketplace manager.
	 *
	 * ## OPTIONS
	 *
	 * <user>
	 * : User ID, login or email.
	 *
	 * @param list<string> $args Arguments.
	 */
	public function appoint( array $args ): void {
		$user_id = self::user( $args[0] ?? '' );
		$this->change( fn() => $this->managers()->appoint( $user_id ) );
		\WP_CLI::success( sprintf( 'User %d is a marketplace manager.', $user_id ) );
	}

	/**
	 * Ends a marketplace manager's appointment.
	 *
	 * ## OPTIONS
	 *
	 * <user>
	 * : User ID, login or email.
	 *
	 * @param list<string> $args Arguments.
	 */
	public function remove( array $args ): void {
		$user_id = self::user( $args[0] ?? '' );
		$this->change( fn() => $this->managers()->remove( $user_id ) );
		\WP_CLI::success( sprintf( 'User %d is no longer a marketplace manager.', $user_id ) );
	}

	/**
	 * Lists the marketplace managers.
	 */
	public function listManagers(): void {
		foreach ( $this->managers()->managers() as $user_id ) {
			$user = get_userdata( $user_id );
			\WP_CLI::log( sprintf( '%d %s', $user_id, false === $user ? '' : $user->user_login ) );
		}
	}

	/**
	 * Runs a change, reporting a domain error as a CLI error.
	 *
	 * @param callable(): void $change Change.
	 */
	private function change( callable $change ): void {
		try {
			$change();
		} catch ( DomainError $error ) {
			\WP_CLI::error( $error->errorCode );
		}
	}

	/**
	 * The appointments service.
	 */
	private function managers(): ManagerAppointments {
		return $this->kernel->container()->get( ManagerAppointments::class );
	}

	/**
	 * A user's ID from an ID, login or email.
	 *
	 * @param string $who User ID, login or email.
	 */
	private static function user( string $who ): int {
		$user = ctype_digit( $who ) ? get_user_by( 'id', (int) $who ) : get_user_by( 'login', $who );
		if ( false === $user && ! ctype_digit( $who ) ) {
			$user = get_user_by( 'email', $who );
		}
		if ( false === $user ) {
			\WP_CLI::error( sprintf( 'No user matches %s.', $who ) );
		}

		return $user->ID;
	}
}
