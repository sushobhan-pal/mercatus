<?php
/**
 * Creates the idempotency table (DATA-2, PLAT-7, IDN-9).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

use Mercatus\Core\Contracts\Database\Migration;
use Mercatus\Core\Contracts\Database\MigrationContext;

return new class() implements Migration {

	public function up( MigrationContext $db ): void {
		$db->statement(
			"CREATE TABLE IF NOT EXISTS {$db->table( 'mercatus_idempotency' )} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				actor_fingerprint VARCHAR(80) CHARACTER SET ascii NOT NULL,
				route VARCHAR(191) CHARACTER SET ascii NOT NULL,
				idempotency_key VARCHAR(255) CHARACTER SET ascii NOT NULL,
				request_hash CHAR(64) CHARACTER SET ascii NOT NULL,
				status VARCHAR(16) NOT NULL,
				response_status SMALLINT UNSIGNED NULL,
				response_body LONGTEXT NULL,
				created_at DATETIME(3) NOT NULL,
				expires_at DATETIME(3) NOT NULL,
				version BIGINT UNSIGNED NOT NULL DEFAULT 1,
				PRIMARY KEY (id),
				UNIQUE KEY actor_route_key (actor_fingerprint, route, idempotency_key),
				KEY expires_at (expires_at)
			) {$db->charsetCollate()}"
		);
	}
};
