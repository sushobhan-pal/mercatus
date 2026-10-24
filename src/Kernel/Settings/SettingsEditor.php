<?php
/**
 * Changing settings from the API (VEN-16).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Kernel\Settings;

use Mercatus\Core\Identity\Application\IdentitySettings;
use Mercatus\Core\Shared\Audit\AuditLog;
use Mercatus\Core\Shared\Errors\DomainError;
use Mercatus\Core\Shared\Events\EventPublisher;
use Mercatus\Core\Shared\Http\ETag;
use Mercatus\Core\Shared\Persistence\TransactionRunner;

/**
 * A module's settings carry no version column, so their ETag hashes the values the administrator sees: a change
 * based on what someone else has since changed is refused (PLAT-9). A change publishes `kernel.settings.updated`
 * with the keys that changed, and `identity.manager.permissions_changed` when the managers' shared permissions
 * did, with an audit entry, in one transaction (API-3). Values stay out of events and the audit log, which hold
 * no free text (SEC-7).
 */
final class SettingsEditor {

	/**
	 * Creates the editor.
	 *
	 * @param SettingsRegistry  $settings     Settings.
	 * @param TransactionRunner $transactions Transactions.
	 * @param EventPublisher    $events       Events.
	 * @param AuditLog          $audit        Audit log.
	 */
	public function __construct( private SettingsRegistry $settings, private TransactionRunner $transactions, private EventPublisher $events, private AuditLog $audit ) {}

	/**
	 * The ETag of a module's settings.
	 *
	 * @param string $module_id Module ID.
	 */
	public function etag( string $module_id ): string {
		return ETag::forContent( $this->settings->module( $module_id ) );
	}

	/**
	 * Changes a module's settings when If-Match names their current ETag.
	 *
	 * @param string               $module_id Module ID.
	 * @param array<string, mixed> $changes   New values by key.
	 * @param string|null          $if_match  The If-Match header.
	 * @return list<string> The keys whose value changed.
	 * @throws DomainError When the settings changed in the meantime, a key is unknown or a value doesn't fit.
	 */
	public function update( string $module_id, array $changes, ?string $if_match ): array {
		if ( ! ETag::matches( $if_match, $this->etag( $module_id ) ) ) {
			throw DomainError::of( 'mercatus_version_conflict' );
		}

		return $this->transactions->run(
			function () use ( $module_id, $changes ): array {
				$changed = $this->settings->update( $module_id, $changes );
				if ( array() === $changed ) {
					return $changed;
				}
				$this->events->publish(
					'kernel.settings.updated',
					'module',
					$module_id,
					0,
					array(
						'module' => $module_id,
						'keys'   => $changed,
					)
				);
				if ( in_array( IdentitySettings::MANAGER_PERMISSIONS, $changed, true ) ) {
					$this->events->publish( 'identity.manager.permissions_changed', 'module', $module_id, 0, array( 'permissions' => IdentitySettings::managerPermissions( $this->settings ) ) );
				}
				$this->audit->record( 'settings.updated', 'module', $module_id, array( 'keys' => implode( ',', $changed ) ) );

				return $changed;
			}
		);
	}
}
