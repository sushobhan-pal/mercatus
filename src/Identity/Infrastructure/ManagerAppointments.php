<?php
/**
 * Appointing and removing marketplace managers (IDN-15).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Identity\Infrastructure;

use Mercatus\Core\Contracts\Identity\IdentityRevisions;
use Mercatus\Core\Shared\Audit\AuditLog;
use Mercatus\Core\Shared\Errors\DomainError;
use Mercatus\Core\Shared\Events\EventPublisher;
use Mercatus\Core\Shared\Persistence\TransactionRunner;

/**
 * The appointment flag, an event and an audit entry in one transaction, then a fresh identity. The flag is
 * user meta, not a WordPress role, so it grants no WordPress or WooCommerce capability.
 */
final class ManagerAppointments {

	/**
	 * Creates the service.
	 *
	 * @param TransactionRunner $transactions Transactions.
	 * @param EventPublisher    $events       Events.
	 * @param AuditLog          $audit        Audit log.
	 * @param IdentityRevisions $revisions    Identity revisions.
	 */
	public function __construct( private TransactionRunner $transactions, private EventPublisher $events, private AuditLog $audit, private IdentityRevisions $revisions ) {}

	/**
	 * Makes a user a marketplace manager. Repeating it changes nothing.
	 *
	 * @param int $user_id WordPress user.
	 */
	public function appoint( int $user_id ): void {
		$this->change( $user_id, true );
	}

	/**
	 * Ends a user's appointment. Repeating it changes nothing.
	 *
	 * @param int $user_id WordPress user.
	 */
	public function remove( int $user_id ): void {
		$this->change( $user_id, false );
	}

	/**
	 * Whether a user is an appointed manager.
	 *
	 * @param int $user_id WordPress user.
	 */
	public function isManager( int $user_id ): bool {
		return '1' === get_user_meta( $user_id, WordPressClaimSource::MANAGER_META, true );
	}

	/**
	 * The appointed managers' user IDs.
	 *
	 * @return list<int>
	 */
	public function managers(): array {
		$ids = get_users(
			array(
				'meta_key'   => WordPressClaimSource::MANAGER_META, // phpcs:ignore WordPress.DB.SlowDBQuery -- a short admin list.
				'meta_value' => '1', // phpcs:ignore WordPress.DB.SlowDBQuery -- a short admin list.
				'fields'     => 'ID',
				'orderby'    => 'ID',
			)
		);

		return array_values( array_map( 'intval', $ids ) );
	}

	/**
	 * Records an appointment change.
	 *
	 * @param int  $user_id WordPress user.
	 * @param bool $on      Appoint or remove.
	 * @throws DomainError When the user doesn't exist.
	 */
	private function change( int $user_id, bool $on ): void {
		if ( false === get_userdata( $user_id ) ) {
			throw DomainError::of( 'mercatus_user_unknown', array( 'user_id' => $user_id ) );
		}
		if ( $this->isManager( $user_id ) === $on ) {
			return;
		}

		$this->transactions->run(
			function () use ( $user_id, $on ): void {
				if ( $on ) {
					update_user_meta( $user_id, WordPressClaimSource::MANAGER_META, '1' );
				} else {
					delete_user_meta( $user_id, WordPressClaimSource::MANAGER_META );
				}
				$change = $on ? 'appointed' : 'removed';
				$this->events->publish( 'identity.manager.' . $change, 'user', $user_id, 0, array( 'user_id' => $user_id ) );
				$this->audit->record( 'manager.' . $change, 'user', $user_id, array( 'manager' => $on ) );
			}
		);
		$this->revisions->bump( $user_id );
	}
}
