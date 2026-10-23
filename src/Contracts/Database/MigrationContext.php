<?php
/**
 * What a migration runs against (DATA-1).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Contracts\Database;

/**
 * Table names with the site's prefix, the site's charset and collation (never hard-coded, G-DB-7), and a way to
 * run a statement.
 */
interface MigrationContext {

	/**
	 * A table's full name, for example wp_mercatus_events_outbox.
	 *
	 * @param string $name Name without the site prefix; Core tables start with mercatus_, Pro tables with mercatus_pro_.
	 * @throws \InvalidArgumentException When the name doesn't start with mercatus_.
	 */
	public function table( string $name ): string;

	/**
	 * The CREATE TABLE suffix with the site's charset and collation.
	 */
	public function charsetCollate(): string;

	/**
	 * Runs one statement.
	 *
	 * @param string $sql Statement. Values never come from user input here; migrations are fixed code.
	 * @throws \RuntimeException When the database reports an error.
	 */
	public function statement( string $sql ): void;
}
