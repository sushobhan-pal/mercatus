<?php
/**
 * core.catalog's migrations (DATA-1, KER-5).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Catalog\Infrastructure;

use Mercatus\Core\Contracts\Database\MigrationSource;

/**
 * `database/migrations/core.catalog/`.
 */
final class CatalogMigrations implements MigrationSource {

	public function directory(): string {
		return dirname( __DIR__, 3 ) . '/database/migrations/core.catalog';
	}
}
