<?php
/**
 * Domain events to outbox rows and back (API-3, KER-8).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Infrastructure\Outbox;

use DateTimeImmutable;
use DateTimeZone;
use Mercatus\Core\Shared\Actor\ActorRef;
use Mercatus\Core\Shared\Events\DomainEvent;
use Mercatus\Core\Shared\Events\EventLane;
use UnexpectedValueException;

/**
 * The payload is JSON and every other field has its own column. Times are UTC with milliseconds (DATA-1).
 * A row that doesn't decode back into a valid event throws, so a corrupt row is retried and then fails,
 * never handled half-read.
 */
final class EventPayloadSerializer {

	private const DATETIME = 'Y-m-d H:i:s.v';

	/**
	 * An outbox row for an event.
	 *
	 * @param DomainEvent       $event        Event.
	 * @param DateTimeImmutable $available_at When it may be handled.
	 * @return array<string, string|int|null>
	 */
	public function toRow( DomainEvent $event, DateTimeImmutable $available_at ): array {
		return array(
			'event_uuid'        => $event->eventId,
			'event_name'        => $event->name,
			'lane'              => $event->lane->value,
			'coalesce_key'      => $event->coalesceKey,
			'aggregate_type'    => $event->aggregateType,
			'aggregate_id'      => $event->aggregateId,
			'aggregate_version' => $event->aggregateVersion,
			'actor_type'        => $event->actor->type,
			'actor_id'          => $event->actor->id,
			'correlation_id'    => $event->correlationId,
			'schema_version'    => $event->schemaVersion,
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- exact, throwing encoding, also used outside WordPress.
			'payload'           => json_encode( $event->payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
			'occurred_at'       => self::format( $event->occurredAt ),
			'available_at'      => self::format( $available_at ),
		);
	}

	/**
	 * The event in an outbox row.
	 *
	 * @param array<string, mixed> $row Row.
	 * @throws UnexpectedValueException When the row doesn't hold a valid event.
	 */
	public function fromRow( array $row ): DomainEvent {
		try {
			$payload = json_decode( self::text( $row, 'payload' ), true, 512, JSON_THROW_ON_ERROR );
			$lane    = EventLane::from( self::text( $row, 'lane' ) );
			$key     = isset( $row['coalesce_key'] ) && '' !== $row['coalesce_key'] ? self::text( $row, 'coalesce_key' ) : null;

			return new DomainEvent(
				self::text( $row, 'event_name' ),
				self::text( $row, 'event_uuid' ),
				new DateTimeImmutable( self::text( $row, 'occurred_at' ), new DateTimeZone( 'UTC' ) ),
				self::text( $row, 'aggregate_type' ),
				self::text( $row, 'aggregate_id' ),
				(int) self::text( $row, 'aggregate_version' ),
				new ActorRef( self::text( $row, 'actor_type' ), (int) self::text( $row, 'actor_id' ) ),
				self::text( $row, 'correlation_id' ),
				is_array( $payload ) ? $payload : array(),
				(int) self::text( $row, 'schema_version' ),
				$lane,
				$key
			);
		} catch ( \Throwable $error ) {
			throw new UnexpectedValueException( 'An outbox row does not hold a valid event.', 0, $error );
		}
	}

	/**
	 * A UTC time with milliseconds.
	 *
	 * @param DateTimeImmutable $time Time.
	 */
	public static function format( DateTimeImmutable $time ): string {
		return $time->setTimezone( new DateTimeZone( 'UTC' ) )->format( self::DATETIME );
	}

	/**
	 * A row field as a string.
	 *
	 * @param array<string, mixed> $row   Row.
	 * @param string               $field Field.
	 * @throws UnexpectedValueException When the field is missing or not scalar.
	 */
	private static function text( array $row, string $field ): string {
		if ( ! isset( $row[ $field ] ) || ! is_scalar( $row[ $field ] ) ) {
			throw new UnexpectedValueException( 'An outbox row is missing a field.' );
		}

		return (string) $row[ $field ];
	}
}
