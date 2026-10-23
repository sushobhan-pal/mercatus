<?php
/**
 * A refusal with a machine-readable code (ARCH-7).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Shared\Errors;

use RuntimeException;

/**
 * Domain and application code throw codes, never user-facing messages; the API layer turns the code into a
 * translated message and an HTTP status (API-1).
 */
class DomainError extends RuntimeException {

	/**
	 * Creates the error.
	 *
	 * @param string               $errorCode Code starting with mercatus_, for example mercatus_module_required_by.
	 * @param array<string, mixed> $details   Context for the API's details field; no personal data.
	 */
	public function __construct( public readonly string $errorCode, public readonly array $details = array() ) {
		parent::__construct( $errorCode );
	}

	/**
	 * Creates the error; reads better at the throw site than a constructor call.
	 *
	 * @param string               $error_code Code starting with mercatus_.
	 * @param array<string, mixed> $details    Context for the API's details field; no personal data.
	 */
	public static function of( string $error_code, array $details = array() ): self {
		return new self( $error_code, $details );
	}
}
