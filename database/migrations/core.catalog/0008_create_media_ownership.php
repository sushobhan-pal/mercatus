<?php
/**
 * Creates the media ownership table (DATA-2, CTL-14, CTL-24).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

use Mercatus\Core\Contracts\Database\Migration;
use Mercatus\Core\Contracts\Database\MigrationContext;

return new class() implements Migration {

	public function up( MigrationContext $db ): void {
		$db->statement(
			"CREATE TABLE IF NOT EXISTS {$db->table( 'mercatus_media_ownership' )} (
				attachment_id BIGINT UNSIGNED NOT NULL,
				vendor_id BIGINT UNSIGNED NOT NULL,
				file VARCHAR(255) NOT NULL,
				thumbnail VARCHAR(255) NOT NULL DEFAULT '',
				mime VARCHAR(32) NOT NULL,
				bytes BIGINT UNSIGNED NOT NULL,
				width INT UNSIGNED NOT NULL,
				height INT UNSIGNED NOT NULL,
				created_at DATETIME(3) NOT NULL,
				PRIMARY KEY (attachment_id),
				KEY vendor_created (vendor_id, created_at DESC)
			) {$db->charsetCollate()}"
		);
	}
};
