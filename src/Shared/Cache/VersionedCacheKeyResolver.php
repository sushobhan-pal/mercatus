<?php
/**
 * Cache keys that carry revision pointers (PLAT-5).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Shared\Cache;

use InvalidArgumentException;
use Mercatus\Core\Contracts\Cache\CachePort;

/**
 * Builds keys such as `p:42:v:1792700000123:loc:3`: the parts in order, with each revision scope's current
 * revision in place of its marker. Bumping a scope's revision changes every key built on it, so entries go out
 * of reach without a flush or a key scan. Keys that would be long or contain unsafe characters are hashed.
 */
final class VersionedCacheKeyResolver {

	private const MAX_LENGTH = 180;

	/**
	 * Creates the resolver.
	 *
	 * @param CachePort $cache Supplies revisions.
	 */
	public function __construct( private CachePort $cache ) {}

	/**
	 * Builds a key.
	 *
	 * @param array<string, int|string|Revision> $parts Labelled parts in order; a Revision is replaced by its scope's current revision.
	 * @throws InvalidArgumentException When there are no parts or a label isn't a short lower-case word.
	 */
	public function resolve( array $parts ): string {
		if ( array() === $parts ) {
			throw new InvalidArgumentException( 'A cache key needs at least one part.' );
		}
		$segments = array();
		foreach ( $parts as $label => $value ) {
			if ( 1 !== preg_match( '/^[a-z][a-z0-9_]{0,15}$/', $label ) ) {
				throw new InvalidArgumentException( 'A cache key label is a short lower-case word.' );
			}
			$segments[] = $label . ':' . ( $value instanceof Revision ? (string) $this->cache->revision( $value->scope ) : (string) $value );
		}
		$key = implode( ':', $segments );

		return strlen( $key ) > self::MAX_LENGTH || 1 !== preg_match( '/^[A-Za-z0-9_.:-]+$/', $key ) ? 'h:' . md5( $key ) : $key;
	}
}
