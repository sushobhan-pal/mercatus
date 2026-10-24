<?php
/**
 * The current request's actor (IDN-13, SEC-1).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Identity\Infrastructure;

use Closure;
use Mercatus\Core\Contracts\Identity\ActorIdentity;
use Mercatus\Core\Contracts\Identity\CurrentActor;
use Mercatus\Core\Identity\Application\IdentityResolver;
use Mercatus\Core\Shared\Actor\ActorRef;
use Mercatus\Core\Shared\Actor\ExecutionContext;
use Mercatus\Core\Shared\Clock\Clock;
use Mercatus\Core\Shared\Id\Uuid;

/**
 * The identity of the user WordPress authenticated, so events and the audit log record SEC-1 types. The
 * system actor exists only inside runAsSystem(), which only the outbox poller and WP-CLI commands call;
 * nothing in a request (query arguments, headers, cookies) can turn it on (TM-39).
 */
final class IdentityExecutionContext implements ExecutionContext, CurrentActor {

	private int $system = 0;

	private ?string $requestId = null;

	/**
	 * Creates the context.
	 *
	 * @param Clock                     $clock    Current time, for request IDs.
	 * @param Closure(): IdentityResolver $resolver The resolver, built once the modules are resolved.
	 */
	public function __construct( private Clock $clock, private Closure $resolver ) {}

	public function actor(): ActorRef {
		return $this->system > 0 ? ActorRef::system() : $this->identity()->ref();
	}

	public function identity(): ActorIdentity {
		return ( $this->resolver )()->resolve( get_current_user_id() );
	}

	public function requestId(): string {
		$this->requestId ??= 'req_' . str_replace( '-', '', Uuid::v7( $this->clock ) );

		return $this->requestId;
	}

	/**
	 * Runs work as the system actor.
	 *
	 * @template T
	 * @param callable(): T $work Work.
	 * @return T
	 */
	public function runAsSystem( callable $work ): mixed {
		++$this->system;
		try {
			return $work();
		} finally {
			--$this->system;
		}
	}
}
