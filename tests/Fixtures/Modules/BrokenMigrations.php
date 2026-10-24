<?php
/**
 * Migrations of a module whose second migration fails.
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Fixtures\Modules;

use Mercatus\Core\Contracts\Database\MigrationSource;

/**
 * Three migrations; the second one fails.
 */
final class BrokenMigrations implements MigrationSource {

	public function directory(): string {
		return dirname( __DIR__ ) . '/Migrations/pro.broken';
	}
}
