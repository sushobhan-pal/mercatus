<?php
/**
 * The application and vendor lifecycles (VEN-1).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Vendor\Domain;

use Mercatus\Core\Shared\State\StateMachine;

/**
 * An application moves from pending to approved or rejected, or is withdrawn; a vendor exists from its first
 * approval and moves between active and suspended, and to closed when its user is deleted.
 */
final class VendorLifecycles {

	public const PENDING   = 'pending';
	public const APPROVED  = 'approved';
	public const REJECTED  = 'rejected';
	public const WITHDRAWN = 'withdrawn';

	public const ACTIVE    = 'active';
	public const SUSPENDED = 'suspended';
	public const CLOSED    = 'closed';

	/**
	 * An application's lifecycle.
	 */
	public static function application(): StateMachine {
		return new StateMachine(
			'vendor_application',
			array(
				self::PENDING   => array( self::APPROVED, self::REJECTED, self::WITHDRAWN ),
				self::APPROVED  => array(),
				self::REJECTED  => array(),
				self::WITHDRAWN => array(),
			)
		);
	}

	/**
	 * A vendor's lifecycle.
	 */
	public static function vendor(): StateMachine {
		return new StateMachine(
			'vendor',
			array(
				self::ACTIVE    => array( self::SUSPENDED, self::CLOSED ),
				self::SUSPENDED => array( self::ACTIVE, self::CLOSED ),
				self::CLOSED    => array(),
			)
		);
	}
}
