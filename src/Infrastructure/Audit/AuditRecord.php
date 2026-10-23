<?php
/**
 * One audit-log entry (KER-9).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Infrastructure\Audit;

use DateTimeImmutable;
use Mercatus\Core\Shared\Actor\ActorRef;

/**
 * Who did what to which resource, when, in which request, and what changed.
 */
final readonly class AuditRecord {

	/**
	 * Creates the record.
	 *
	 * @param ActorRef                            $actor        Actor.
	 * @param string                              $action       Action.
	 * @param string                              $resourceType Resource type.
	 * @param string                              $resourceId   Resource ID.
	 * @param string                              $requestId    Request ID.
	 * @param array<string, int|string|bool|null> $changes      What changed.
	 * @param DateTimeImmutable                   $createdAt    When.
	 */
	public function __construct(
		public ActorRef $actor,
		public string $action,
		public string $resourceType,
		public string $resourceId,
		public string $requestId,
		public array $changes,
		public DateTimeImmutable $createdAt,
	) {}
}
