<?php
/**
 * Creates the vendor applications table (DATA-2, VEN-11).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

use Mercatus\Core\Contracts\Database\Migration;
use Mercatus\Core\Contracts\Database\MigrationContext;

return new class() implements Migration {

	public function up( MigrationContext $db ): void {
		$db->statement(
			"CREATE TABLE IF NOT EXISTS {$db->table( 'mercatus_vendor_applications' )} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				user_id BIGINT UNSIGNED NOT NULL,
				attempt INT UNSIGNED NOT NULL,
				status VARCHAR(16) NOT NULL,
				store_name VARCHAR(191) NOT NULL,
				answers LONGTEXT NOT NULL,
				reason TEXT NULL,
				decided_by BIGINT UNSIGNED NULL,
				decided_at DATETIME(3) NULL,
				created_at DATETIME(3) NOT NULL,
				updated_at DATETIME(3) NOT NULL,
				version BIGINT UNSIGNED NOT NULL DEFAULT 1,
				PRIMARY KEY (id),
				UNIQUE KEY user_attempt (user_id, attempt),
				KEY status_created (status, created_at)
			) {$db->charsetCollate()}"
		);
	}
};
