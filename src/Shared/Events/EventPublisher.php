<?php
/**
 * Publishes events into the current transaction (API-3).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Shared\Events;

/**
 * Commands publish while TransactionRunner::run() is running; the events are written to the outbox in the
 * same transaction as the command's state, and handled only after commit.
 */
interface EventPublisher {

	/**
	 * Publishes an event; the actor, request and time come from the execution context.
	 *
	 * @param string               $name             {module}.{aggregate}.{past_tense}.
	 * @param string               $aggregate_type   Aggregate type.
	 * @param int|string           $aggregate_id     Aggregate ID.
	 * @param int                  $aggregate_version Aggregate version after the change.
	 * @param array<string, mixed> $payload          Event data.
	 * @param int                  $schema_version   Payload schema version.
	 * @param EventLane            $lane             Outbox lane.
	 * @param string|null          $coalesce_key     Coalesce key for bulk events.
	 * @throws \LogicException When no transaction is running.
	 */
	public function publish( string $name, string $aggregate_type, int|string $aggregate_id, int $aggregate_version, array $payload, int $schema_version = 1, EventLane $lane = EventLane::Critical, ?string $coalesce_key = null ): DomainEvent;
}
