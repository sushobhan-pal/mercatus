<?php
/**
 * Who may call a route (Groundwork §4, TEST-2).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Contracts\Http;

/**
 * The access level every endpoint declares in its route metadata: 'mercatus' => array( 'access' => … ).
 */
enum Access: string {
	case Open          = 'public';
	case Authenticated = 'authenticated';
	case Tenant        = 'tenant';
	case Admin         = 'admin';
}
