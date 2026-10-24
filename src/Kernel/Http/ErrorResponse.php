<?php
/**
 * Turns DomainError codes into REST errors (ARCH-7, API-1).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Kernel\Http;

use Mercatus\Core\Shared\Errors\DomainError;
use Mercatus\Core\Shared\Http\ApiError;
use WP_Error;

/**
 * The kernel's codes with their HTTP status and translated message. The base REST controller in
 * `core.identity` takes this over for every module, with the full API-1 envelope.
 */
final class ErrorResponse {

	/**
	 * A REST error for a domain error.
	 *
	 * @param DomainError $error Error.
	 */
	public static function from( DomainError $error ): WP_Error {
		return ApiError::from(
			$error,
			array(
				'mercatus_module_unknown'      => array( 404, __( 'There is no such module.', 'mercatus' ) ),
				'mercatus_module_not_optional' => array( 409, __( 'This module is always on.', 'mercatus' ) ),
				'mercatus_module_requires'     => array( 409, __( 'Switch on the module this one needs first.', 'mercatus' ) ),
				'mercatus_module_required_by'  => array( 409, __( 'Another active module needs this one.', 'mercatus' ) ),
				'mercatus_setting_unknown'     => array( 404, __( 'There is no such setting.', 'mercatus' ) ),
				'mercatus_setting_invalid'     => array( 422, __( 'That value is not allowed for this setting.', 'mercatus' ) ),
			)
		);
	}
}
