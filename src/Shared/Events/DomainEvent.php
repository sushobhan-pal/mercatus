<?php
/**
 * Something that happened (ARCH-8 point 2, KER-8).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Shared\Events;

use DateTimeImmutable;
use InvalidArgumentException;
use Mercatus\Core\Shared\Actor\ActorRef;

/**
 * Immutable, and passed to subscribers and to WordPress after commit. The payload holds only strings,
 * integers, booleans, null and arrays of those, so it serialises exactly (no floats, G-MONEY-1).
 */
final readonly class DomainEvent {

	private const NAME = '/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/';

	/**
	 * Creates the event.
	 *
	 * @param string               $name             {module}.{aggregate}.{past_tense}, for example vendor.application.approved.
	 * @param string               $eventId          UUID.
	 * @param DateTimeImmutable    $occurredAt       When it happened, in UTC.
	 * @param string               $aggregateType    Aggregate type, for example vendor_application.
	 * @param string               $aggregateId      Aggregate ID.
	 * @param int                  $aggregateVersion Aggregate version after the change; 0 when the aggregate has none.
	 * @param ActorRef             $actor            Who caused it.
	 * @param string               $correlationId    Ties together everything one request caused.
	 * @param array<string, mixed> $payload          Event data; its shape is versioned by $schemaVersion.
	 * @param int                  $schemaVersion    Payload schema version, 1 or more.
	 * @param EventLane            $lane             Outbox lane.
	 * @param string|null          $coalesceKey      Bulk events with the same key may be merged into the latest.
	 * @throws InvalidArgumentException When a field is malformed.
	 */
	public function __construct(
		public string $name,
		public string $eventId,
		public DateTimeImmutable $occurredAt,
		public string $aggregateType,
		public string $aggregateId,
		public int $aggregateVersion,
		public ActorRef $actor,
		public string $correlationId,
		public array $payload,
		public int $schemaVersion = 1,
		public EventLane $lane = EventLane::Critical,
		public ?string $coalesceKey = null,
	) {
		if ( 1 !== preg_match( self::NAME, $name ) ) {
			throw new InvalidArgumentException( 'An event name is {module}.{aggregate}.{past_tense}.' );
		}
		if ( '' === $eventId || '' === $aggregateType || '' === $aggregateId || '' === $correlationId || $aggregateVersion < 0 || $schemaVersion < 1 ) {
			throw new InvalidArgumentException( 'An event needs an ID, an aggregate, a correlation ID and valid versions.' );
		}
		if ( null !== $coalesceKey && ( EventLane::Bulk !== $lane || '' === $coalesceKey ) ) {
			throw new InvalidArgumentException( 'Only bulk events have a coalesce key.' );
		}
		self::assertPlain( $payload );
	}

	/**
	 * The name of the WordPress action that carries this event, for example mercatus_event_vendor_application_approved.
	 *
	 * @return non-empty-string
	 */
	public function actionName(): string {
		return 'mercatus_event_' . str_replace( '.', '_', $this->name );
	}

	/**
	 * Refuses payload values that don't serialise exactly.
	 *
	 * @param array<mixed> $values Payload or part of it.
	 * @throws InvalidArgumentException When a value is a float, an object or a resource.
	 */
	private static function assertPlain( array $values ): void {
		foreach ( $values as $value ) {
			if ( is_array( $value ) ) {
				self::assertPlain( $value );
			} elseif ( ! ( null === $value || is_string( $value ) || is_int( $value ) || is_bool( $value ) ) ) {
				throw new InvalidArgumentException( 'An event payload holds only strings, integers, booleans, null and arrays.' );
			}
		}
	}
}
