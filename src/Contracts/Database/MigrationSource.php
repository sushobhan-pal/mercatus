<?php
/**
 * Where a module keeps its migrations (ARCH-6, KER-5).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Contracts\Database;

/**
 * Named by a module manifest's `migrations` field.
 */
interface MigrationSource {

	/**
	 * Absolute path of the folder holding the module's migration files, named NNNN_short_description.php.
	 */
	public function directory(): string;
}
