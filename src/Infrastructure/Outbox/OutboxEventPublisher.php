<?php
/**
 * EventPublisher that writes to the outbox (API-3).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Infrastructure\Outbox;

use Mercatus\Core\Infrastructure\Database\UnitOfWork;
use Mercatus\Core\Shared\Actor\ExecutionContext;
use Mercatus\Core\Shared\Clock\Clock;
use Mercatus\Core\Shared\Events\DomainEvent;
use Mercatus\Core\Shared\Events\EventLane;
use Mercatus\Core\Shared\Events\EventPublisher;
use Mercatus\Core\Shared\Id\Uuid;

/**
 * Builds the event with a UUIDv7, the current time, actor and request, and buffers it in the transaction.
 */
final class OutboxEventPublisher implements EventPublisher {

	/**
	 * Creates the publisher.
	 *
	 * @param UnitOfWork       $unit    Transaction buffer.
	 * @param ExecutionContext $context Actor and request.
	 * @param Clock            $clock   Current time.
	 */
	public function __construct( private UnitOfWork $unit, private ExecutionContext $context, private Clock $clock ) {}

	public function publish( string $name, string $aggregate_type, int|string $aggregate_id, int $aggregate_version, array $payload, int $schema_version = 1, EventLane $lane = EventLane::Critical, ?string $coalesce_key = null ): DomainEvent {
		$event = new DomainEvent( $name, Uuid::v7( $this->clock ), $this->clock->now(), $aggregate_type, (string) $aggregate_id, $aggregate_version, $this->context->actor(), $this->context->requestId(), $payload, $schema_version, $lane, $coalesce_key );
		$this->unit->addEvent( $event );

		return $event;
	}
}
