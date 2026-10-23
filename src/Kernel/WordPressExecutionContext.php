<?php
/**
 * The actor and request as WordPress sees them (SEC-1, KER-7).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Kernel;

use Mercatus\Core\Shared\Actor\ActorRef;
use Mercatus\Core\Shared\Actor\ExecutionContext;
use Mercatus\Core\Shared\Clock\Clock;
use Mercatus\Core\Shared\Id\Uuid;

/**
 * The signed-in WordPress user, or anonymous. The system actor exists only inside runAsSystem(), which only
 * the outbox poller and WP-CLI commands call; nothing in a request (query arguments, headers, cookies) can
 * turn it on (TM-39). `core.identity` replaces this with ActorIdentity.
 */
final class WordPressExecutionContext implements ExecutionContext {

	private int $system = 0;

	private ?string $requestId = null;

	/**
	 * Creates the context.
	 *
	 * @param Clock $clock Current time, for request IDs.
	 */
	public function __construct( private Clock $clock ) {}

	public function actor(): ActorRef {
		if ( $this->system > 0 ) {
			return ActorRef::system();
		}
		$user = get_current_user_id();

		return $user > 0 ? new ActorRef( 'user', $user ) : ActorRef::anonymous();
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
