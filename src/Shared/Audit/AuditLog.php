<?php
/**
 * Records who changed what (API-3, KER-9).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Shared\Audit;

/**
 * Entries are written in the current transaction. Changes record keys, IDs and old and new statuses, never
 * free text or personal data (SEC-7).
 */
interface AuditLog {

	/**
	 * Records an action.
	 *
	 * @param string                            $action        Action, for example module.activated.
	 * @param string                            $resource_type Resource type.
	 * @param int|string                        $resource_id   Resource ID.
	 * @param array<string, int|string|bool|null> $changes       What changed, by field.
	 * @throws \LogicException When no transaction is running.
	 */
	public function record( string $action, string $resource_type, int|string $resource_id, array $changes = array() ): void;
}
