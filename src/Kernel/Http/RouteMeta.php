<?php
/**
 * A route endpoint's Mercatus metadata (Groundwork §4, IDN-4, IDN-9, IDN-10).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Kernel\Http;

use Mercatus\Core\Contracts\Http\Access;

/**
 * Read from the endpoint's `'mercatus'` array.
 */
final readonly class RouteMeta {

	/**
	 * Creates the metadata.
	 *
	 * @param Access      $access      Access level.
	 * @param string|null $permission  Named permission, if any.
	 * @param bool        $idempotent  Whether the route requires an Idempotency-Key (PLAT-7).
	 * @param int|null    $rateLimit   A tighter per-minute limit for this route, if any (IDN-19).
	 */
	public function __construct( public Access $access, public ?string $permission = null, public bool $idempotent = false, public ?int $rateLimit = null ) {}

	/**
	 * The metadata of an endpoint, or null when it has none or it's malformed.
	 *
	 * @param array<mixed> $endpoint Endpoint.
	 */
	public static function of( array $endpoint ): ?self {
		$meta       = is_array( $endpoint['mercatus'] ?? null ) ? $endpoint['mercatus'] : array();
		$access     = is_string( $meta['access'] ?? null ) ? Access::tryFrom( $meta['access'] ) : null;
		$permission = $meta['permission'] ?? null;
		$limit      = $meta['rate_limit'] ?? null;
		$idempotent = $meta['idempotency'] ?? null;
		if ( null === $access || ( null !== $permission && ! is_string( $permission ) ) || ( null !== $limit && ( ! is_int( $limit ) || $limit < 1 ) ) || ( null !== $idempotent && 'required' !== $idempotent ) ) {
			return null;
		}

		return new self( $access, $permission, 'required' === $idempotent, $limit );
	}
}
