<?php
/**
 * Creates the API clients table (DATA-2, SEC-1, IDN-11, IDN-17).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

use Mercatus\Core\Contracts\Database\Migration;
use Mercatus\Core\Contracts\Database\MigrationContext;

return new class() implements Migration {

	public function up( MigrationContext $db ): void {
		$db->statement(
			"CREATE TABLE IF NOT EXISTS {$db->table( 'mercatus_api_clients' )} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				client_key_hash CHAR(64) CHARACTER SET ascii NOT NULL,
				name VARCHAR(191) NOT NULL,
				actor_type VARCHAR(32) NOT NULL,
				actor_id BIGINT UNSIGNED NOT NULL,
				scopes LONGTEXT NOT NULL,
				ip_allow_list LONGTEXT NOT NULL,
				password_lifetime_days INT UNSIGNED NOT NULL DEFAULT 0,
				status VARCHAR(16) NOT NULL,
				last_used_at DATETIME(3) NULL,
				created_by BIGINT UNSIGNED NOT NULL,
				created_at DATETIME(3) NOT NULL,
				updated_at DATETIME(3) NOT NULL,
				version BIGINT UNSIGNED NOT NULL DEFAULT 1,
				PRIMARY KEY (id),
				UNIQUE KEY client_key_hash (client_key_hash),
				KEY actor_status (actor_type, actor_id, status)
			) {$db->charsetCollate()}"
		);
	}
};
