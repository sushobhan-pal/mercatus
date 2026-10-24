<?php
/**
 * core.identity's WordPress hooks (IDN-2).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Identity\Infrastructure;

use Mercatus\Core\Contracts\Identity\IdentityRevisions;
use Mercatus\Core\Contracts\Module\ModuleHooks;

/**
 * A role change, a super-admin change or a deleted user resolves afresh on the next request; an application
 * password WordPress authenticates is checked against its API client (IDN-11).
 */
final class IdentityHooks implements ModuleHooks {

	private const USER_CHANGES = array( 'set_user_role', 'add_user_role', 'remove_user_role', 'deleted_user', 'granted_super_admin', 'revoked_super_admin' );

	/**
	 * Creates the hooks.
	 *
	 * @param IdentityRevisions       $revisions Identity revisions.
	 * @param ApiClientAuthentication $clients   The request's application password.
	 */
	public function __construct( private IdentityRevisions $revisions, private ApiClientAuthentication $clients ) {}

	public function register(): void {
		foreach ( self::USER_CHANGES as $hook ) {
			add_action( $hook, array( $this, 'forget' ) );
		}
		add_action( 'application_password_did_authenticate', array( $this->clients, 'authenticated' ), 10, 2 );
	}

	/**
	 * Bumps a user's identity revision.
	 *
	 * @param int|string $user_id WordPress user.
	 */
	public function forget( int|string $user_id ): void {
		$this->revisions->bump( (int) $user_id );
	}
}
