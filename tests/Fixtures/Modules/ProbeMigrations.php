<?php
/**
 * Migrations of the probe module used by kernel tests.
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Fixtures\Modules;

use Mercatus\Core\Contracts\Database\MigrationSource;

/**
 * Two migrations, each creating a table.
 */
final class ProbeMigrations implements MigrationSource {

	public function directory(): string {
		return dirname( __DIR__ ) . '/Migrations/pro.probe';
	}
}
