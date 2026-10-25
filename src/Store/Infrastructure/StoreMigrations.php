<?php
/**
 * core.store's migrations (DATA-1, KER-5).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Store\Infrastructure;

use Mercatus\Core\Contracts\Database\MigrationSource;

/**
 * `database/migrations/core.store/`.
 */
final class StoreMigrations implements MigrationSource {

	public function directory(): string {
		return dirname( __DIR__, 3 ) . '/database/migrations/core.store';
	}
}
