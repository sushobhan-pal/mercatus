<?php
/**
 * The projection runner (DRY-2, CAT-5, ARCH-12).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Shared\Projection;

use Mercatus\Core\Contracts\Cache\CachePort;

/**
 * Rebuilds a key's rows from the sources of truth and writes only what changed, so a rebuild that finds
 * nothing new costs its reads and no write. A vendor's own change rebuilds inside its command (ARCH-12) and
 * bumps the revision after the transaction commits; bulk-lane handlers and the reconciler let the runner bump.
 */
final class ProjectionRunner {

	/**
	 * Creates the runner.
	 *
	 * @param CachePort $cache Cache, for revisions.
	 */
	public function __construct( private CachePort $cache ) {}

	/**
	 * Brings a key's rows up to date.
	 *
	 * @param Projector $projector Projection.
	 * @param int       $key       Projection key.
	 * @param bool      $bump      Whether to bump the key's revision now; false inside a transaction, whose
	 *                             caller bumps it after commit.
	 * @return bool Whether anything changed.
	 */
	public function rebuild( Projector $projector, int $key, bool $bump = true ): bool {
		$desired = $projector->desired( $key );
		$stored  = $projector->stored( $key );
		$upserts = array();
		foreach ( $desired as $row_key => $row ) {
			if ( ! isset( $stored[ $row_key ] ) || self::sorted( $stored[ $row_key ] ) !== self::sorted( $row ) ) {
				$upserts[ $row_key ] = $row;
			}
		}
		$deletes = array_values( array_map( 'strval', array_diff( array_keys( $stored ), array_keys( $desired ) ) ) );
		if ( array() === $upserts && array() === $deletes ) {
			return false;
		}
		$projector->write( $key, $upserts, $deletes );
		if ( $bump ) {
			$this->cache->bumpRevision( $projector->scope( $key ) );
		}

		return true;
	}

	/**
	 * A row with its columns in a fixed order, so two rows compare by content alone.
	 *
	 * @param array<string, string|null> $row Row.
	 * @return array<string, string|null>
	 */
	private static function sorted( array $row ): array {
		ksort( $row );

		return $row;
	}
}
