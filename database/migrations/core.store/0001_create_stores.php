<?php
/**
 * Creates the stores table (DATA-2, STO-1).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

use Mercatus\Core\Contracts\Database\Migration;
use Mercatus\Core\Contracts\Database\MigrationContext;

return new class() implements Migration {

	public function up( MigrationContext $db ): void {
		$db->statement(
			"CREATE TABLE IF NOT EXISTS {$db->table( 'mercatus_stores' )} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				vendor_id BIGINT UNSIGNED NOT NULL,
				name VARCHAR(191) NOT NULL,
				slug VARCHAR(64) NOT NULL,
				tagline VARCHAR(191) NOT NULL DEFAULT '',
				description TEXT NULL,
				contact_email VARCHAR(191) NOT NULL DEFAULT '',
				contact_phone VARCHAR(40) NOT NULL DEFAULT '',
				show_email TINYINT(1) NOT NULL DEFAULT 0,
				show_phone TINYINT(1) NOT NULL DEFAULT 0,
				policy_shipping TEXT NULL,
				policy_returns TEXT NULL,
				policy_privacy TEXT NULL,
				logo_id BIGINT UNSIGNED NULL,
				banner_id BIGINT UNSIGNED NULL,
				visibility VARCHAR(16) NOT NULL,
				status VARCHAR(16) NOT NULL,
				created_at DATETIME(3) NOT NULL,
				updated_at DATETIME(3) NOT NULL,
				version BIGINT UNSIGNED NOT NULL DEFAULT 1,
				PRIMARY KEY (id),
				UNIQUE KEY vendor_id (vendor_id),
				UNIQUE KEY slug (slug),
				KEY visibility_status_name (visibility, status, name, id)
			) {$db->charsetCollate()}"
		);
	}
};
