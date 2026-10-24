<?php
/**
 * The kernel's own migrations (DATA-2).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Kernel;

use Mercatus\Core\Contracts\Database\MigrationSource;

/**
 * The outbox and the audit log; `schema_versions` is created by the runner itself.
 */
final class KernelMigrations implements MigrationSource {

	public function directory(): string {
		return dirname( __DIR__, 2 ) . '/database/migrations/core.kernel';
	}
}
