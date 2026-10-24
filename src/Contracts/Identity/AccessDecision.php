<?php
/**
 * The outcome of an access check (IDN-4, SEC-4, VEN-2).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Contracts\Identity;

/**
 * Mapped to 401, 403 or 404 at the edge.
 */
enum AccessDecision: string {
	case Allowed         = 'allowed';
	case Unauthenticated = 'unauthenticated';
	case Forbidden       = 'forbidden';
	case NotFound        = 'not_found';

	/**
	 * The HTTP status of a refusal; 200 when allowed.
	 */
	public function status(): int {
		return match ( $this ) { // phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext -- $this is the enum case; the sniff predates enums.
			self::Allowed         => 200,
			self::Unauthenticated => 401,
			self::Forbidden       => 403,
			self::NotFound        => 404,
		};
	}
}
