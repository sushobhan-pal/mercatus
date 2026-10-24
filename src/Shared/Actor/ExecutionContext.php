<?php
/**
 * Who is acting, and in which request (SEC-1, API-1).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Shared\Actor;

/**
 * Events and audit entries take their actor and request ID from here.
 */
interface ExecutionContext {

	/**
	 * The current actor.
	 */
	public function actor(): ActorRef;

	/**
	 * The current request's ID, such as req_0192…, shared by everything the request records.
	 */
	public function requestId(): string;
}
