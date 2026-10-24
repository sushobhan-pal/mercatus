<?php
/**
 * core.vendor's migrations (DATA-1, KER-5).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Vendor\Infrastructure;

use Mercatus\Core\Contracts\Database\MigrationSource;

/**
 * `database/migrations/core.vendor/`.
 */
final class VendorMigrations implements MigrationSource {

	public function directory(): string {
		return dirname( __DIR__, 3 ) . '/database/migrations/core.vendor';
	}
}
