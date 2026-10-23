<?php
/**
 * Fixture migration that fails.
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

use Mercatus\Core\Contracts\Database\Migration;
use Mercatus\Core\Contracts\Database\MigrationContext;

return new class() implements Migration {

	public function up( MigrationContext $db ): void {
		$db->statement( 'SELECT * FROM a_table_that_does_not_exist_anywhere' );
	}
};
