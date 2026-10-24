<?php
/**
 * A module's migration files (KER-5).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Kernel\Migrations;

/**
 * Files named NNNN_short_description.php, by version, each with the SHA-256 of its contents. Reading the
 * folder touches only the file system, never the database.
 */
final class MigrationFiles {

	private const NAME = '/^(\d{4})_[a-z0-9_]+\.php$/';

	/**
	 * Migration files in a folder, ascending by version.
	 *
	 * @param string $directory Folder.
	 * @return array<int, string> Path by version.
	 */
	public static function in( string $directory ): array {
		$files = array();
		foreach ( is_dir( $directory ) ? (array) scandir( $directory ) : array() as $name ) {
			if ( is_string( $name ) && 1 === preg_match( self::NAME, $name, $match ) ) {
				$files[ (int) $match[1] ] = $directory . '/' . $name;
			}
		}
		ksort( $files );

		return $files;
	}

	/**
	 * The highest version in a folder, or 0 when it has none.
	 *
	 * @param string $directory Folder.
	 */
	public static function latest( string $directory ): int {
		$versions = array_keys( self::in( $directory ) );

		return array() === $versions ? 0 : (int) max( $versions );
	}

	/**
	 * SHA-256 of a file's contents.
	 *
	 * @param string $path File.
	 */
	public static function checksum( string $path ): string {
		return (string) hash_file( 'sha256', $path );
	}
}
