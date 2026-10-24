<?php
/**
 * Turns domain errors into REST errors (ARCH-7, API-1).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Shared\Http;

use Mercatus\Core\Shared\Errors\DomainError;
use WP_Error;

/**
 * Each module's API layer passes its own map of codes to statuses and translated messages; an unmapped code
 * is a 400. The kernel turns the WP_Error into the API-1 envelope.
 */
final class ApiError {

	/**
	 * The REST error for a domain error.
	 *
	 * @param DomainError                       $error Error.
	 * @param array<string, array{int, string}> $map   Status and translated message by error code.
	 */
	public static function from( DomainError $error, array $map = array() ): WP_Error {
		list( $status, $message ) = $map[ $error->errorCode ] ?? array( 400, __( 'The request could not be completed.', 'mercatus' ) );

		return new WP_Error( $error->errorCode, $message, array_merge( array( 'status' => $status ), $error->details ) );
	}
}
