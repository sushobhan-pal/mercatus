<?php
/**
 * Fixture migration: creates mercatus_pro_probe_notes.
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

use Mercatus\Core\Contracts\Database\Migration;
use Mercatus\Core\Contracts\Database\MigrationContext;

return new class() implements Migration {

	public function up( MigrationContext $db ): void {
		$db->statement( "CREATE TABLE IF NOT EXISTS {$db->table( 'mercatus_pro_probe_notes' )} ( id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, PRIMARY KEY (id) ) {$db->charsetCollate()}" );
	}
};
