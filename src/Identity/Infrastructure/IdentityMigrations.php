<?php
/**
 * core.identity's migrations (DATA-1, KER-5).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Identity\Infrastructure;

use Mercatus\Core\Contracts\Database\MigrationSource;

/**
 * `database/migrations/core.identity/`.
 */
final class IdentityMigrations implements MigrationSource {

	public function directory(): string {
		return dirname( __DIR__, 3 ) . '/database/migrations/core.identity';
	}
}
