<?php
/**
 * MigrationContext over $wpdb (DATA-1).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Infrastructure\Database;

use InvalidArgumentException;
use Mercatus\Core\Contracts\Database\MigrationContext;
use RuntimeException;
use wpdb;

/**
 * Runs migration statements through WordPress's database connection.
 */
final class WpdbMigrationContext implements MigrationContext {

	/**
	 * Creates the context.
	 *
	 * @param wpdb $db WordPress database.
	 */
	public function __construct( private wpdb $db ) {}

	public function table( string $name ): string {
		if ( 1 !== preg_match( '/^mercatus_[a-z0-9_]+$/', $name ) ) {
			throw new InvalidArgumentException( 'Mercatus table names start with mercatus_.' );
		}

		return $this->db->prefix . $name;
	}

	public function charsetCollate(): string {
		return $this->db->get_charset_collate();
	}

	public function statement( string $sql ): void {
		// A failing statement is reported by the runner, never printed into the page.
		$suppressed = $this->db->suppress_errors( true );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- fixed schema statements from migration files.
		$result = $this->db->query( $sql );
		$this->db->suppress_errors( $suppressed );
		if ( false === $result ) {
			throw new RuntimeException( 'A migration statement failed.' );
		}
	}
}
