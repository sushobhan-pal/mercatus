<?php
/**
 * Reports refused duplicate permissions (ARCH-6).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Identity\Application;

use Mercatus\Core\Contracts\Health\HealthCheck;
use Mercatus\Core\Contracts\Health\HealthResult;
use Mercatus\Core\Contracts\Health\HealthStatus;

/**
 * A duplicate name means one module's routes or menu items can't be granted as intended.
 */
final class PermissionsHealthCheck implements HealthCheck {

	/**
	 * Creates the check.
	 *
	 * @param PermissionCatalog $catalog Permissions.
	 */
	public function __construct( private PermissionCatalog $catalog ) {}

	public function id(): string {
		return 'identity.permissions';
	}

	public function check(): HealthResult {
		$refused = $this->catalog->refused();

		return array() === $refused
			? new HealthResult( HealthStatus::Ok, 'mercatus_permissions_unique' )
			: new HealthResult( HealthStatus::Error, 'mercatus_permissions_duplicated', array( 'refused' => $refused ) );
	}
}
