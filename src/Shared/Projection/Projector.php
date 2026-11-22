<?php
/**
 * One read projection, for the projection runner (DRY-2, CAT-5).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Shared\Projection;

/**
 * A projection keeps rows per key (for example a catalog product). Rows are given in their stored form (strings
 * and nulls, keyed by column), each under a key that names the row within its key's rows, so the runner can
 * compare what's stored with what should be and write only the difference.
 */
interface Projector {

	/**
	 * The rows a key should have, from the sources of truth.
	 *
	 * @param int $key Projection key.
	 * @return array<string, array<string, string|null>> Rows by row key.
	 */
	public function desired( int $key ): array;

	/**
	 * The rows a key has now.
	 *
	 * @param int $key Projection key.
	 * @return array<string, array<string, string|null>> Rows by row key.
	 */
	public function stored( int $key ): array;

	/**
	 * Writes the difference.
	 *
	 * @param int                                       $key     Projection key.
	 * @param array<string, array<string, string|null>> $upserts Rows to insert or replace, by row key.
	 * @param list<string>                              $deletes Row keys to remove.
	 */
	public function write( int $key, array $upserts, array $deletes ): void;

	/**
	 * The cache revision scope that covers a key's rows (PLAT-5).
	 *
	 * @param int $key Projection key.
	 */
	public function scope( int $key ): string;
}
