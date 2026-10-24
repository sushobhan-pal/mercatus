<?php
/**
 * One forward-only schema change (DATA-1, KER-5).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Contracts\Database;

/**
 * Each migration file returns one of these. Migrations never go down; a later migration corrects an earlier
 * one. Write them to be safe to repeat, for example with CREATE TABLE IF NOT EXISTS.
 */
interface Migration {

	/**
	 * Applies the change.
	 *
	 * @param MigrationContext $db Where to run it.
	 */
	public function up( MigrationContext $db ): void;
}
