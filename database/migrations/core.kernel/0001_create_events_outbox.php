<?php
/**
 * Creates the outbox (DATA-2, API-3, KER-7).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

use Mercatus\Core\Contracts\Database\Migration;
use Mercatus\Core\Contracts\Database\MigrationContext;

return new class() implements Migration {

	public function up( MigrationContext $db ): void {
		$db->statement(
			"CREATE TABLE IF NOT EXISTS {$db->table( 'mercatus_events_outbox' )} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				event_uuid CHAR(36) NOT NULL,
				event_name VARCHAR(191) NOT NULL,
				lane VARCHAR(16) NOT NULL,
				coalesce_key VARCHAR(191) NULL,
				aggregate_type VARCHAR(64) NOT NULL,
				aggregate_id VARCHAR(64) NOT NULL,
				aggregate_version BIGINT UNSIGNED NOT NULL,
				actor_type VARCHAR(32) NOT NULL,
				actor_id BIGINT UNSIGNED NOT NULL,
				correlation_id VARCHAR(64) NOT NULL,
				schema_version INT UNSIGNED NOT NULL,
				payload LONGTEXT NOT NULL,
				status VARCHAR(16) NOT NULL DEFAULT 'pending',
				attempts INT UNSIGNED NOT NULL DEFAULT 0,
				last_error_code VARCHAR(191) NULL,
				occurred_at DATETIME(3) NOT NULL,
				available_at DATETIME(3) NOT NULL,
				claimed_by VARCHAR(64) NULL,
				lease_until DATETIME(3) NULL,
				processed_at DATETIME(3) NULL,
				PRIMARY KEY (id),
				UNIQUE KEY event_uuid (event_uuid),
				KEY idx_outbox_poll (lane, status, available_at, id),
				KEY status_lease (status, lease_until),
				KEY coalesce_status (coalesce_key, status)
			) {$db->charsetCollate()}"
		);
	}
};
