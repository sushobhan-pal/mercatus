<?php
/**
 * Domain events to outbox rows and back (TEST-3 row 3, KER-8).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Unit\Outbox;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Mercatus\Core\Infrastructure\Outbox\EventPayloadSerializer;
use Mercatus\Core\Shared\Actor\ActorRef;
use Mercatus\Core\Shared\Events\DomainEvent;
use Mercatus\Core\Shared\Events\EventLane;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

/**
 * Exact round trips, refused payload values and refused corrupt rows.
 */
final class EventPayloadSerializerTest extends TestCase {

	public function testRoundTripsEveryField(): void {
		$event      = $this->event(
			array(
				'amount_minor' => 125000,
				'currency'     => 'INR',
				'note'         => 'Ünïcode / slashes',
				'tags'         => array( 'a', null, true ),
			),
			EventLane::Bulk,
			'store:7'
		);
		$serializer = new EventPayloadSerializer();

		$row = $serializer->toRow( $event, new DateTimeImmutable( '2026-10-23 12:00:01.250', new DateTimeZone( 'UTC' ) ) );

		$this->assertSame( '2026-10-23 12:00:00.123', $row['occurred_at'] );
		$this->assertSame( '2026-10-23 12:00:01.250', $row['available_at'] );
		$this->assertSame( '{"amount_minor":125000,"currency":"INR","note":"Ünïcode / slashes","tags":["a",null,true]}', $row['payload'] );
		$this->assertEquals( $event, $serializer->fromRow( $row ) );
	}

	public function testWritesTimesInUtc(): void {
		$local = $this->event( array(), occurred: new DateTimeImmutable( '2026-10-23 17:30:00', new DateTimeZone( 'Asia/Kolkata' ) ) );

		$this->assertSame( '2026-10-23 12:00:00.000', ( new EventPayloadSerializer() )->toRow( $local, new DateTimeImmutable() )['occurred_at'] );
	}

	/**
	 * Payload values events refuse.
	 *
	 * @return array<string, array{array<string, mixed>}>
	 */
	public static function inexactPayloads(): array {
		return array(
			'float'        => array( array( 'rate' => 0.18 ) ),
			'nested float' => array( array( 'lines' => array( array( 'tax' => 1.5 ) ) ) ),
			'object'       => array( array( 'when' => new DateTimeImmutable() ) ),
		);
	}

	/**
	 * Refuses payload values that wouldn't serialise exactly.
	 *
	 * @dataProvider inexactPayloads
	 * @param array<string, mixed> $payload Payload.
	 */
	public function testRefusesInexactPayloads( array $payload ): void {
		$this->expectException( InvalidArgumentException::class );
		$this->event( $payload );
	}

	public function testRefusesACorruptRow(): void {
		$row            = ( new EventPayloadSerializer() )->toRow( $this->event( array() ), new DateTimeImmutable() );
		$row['payload'] = '{not json';

		$this->expectException( UnexpectedValueException::class );
		( new EventPayloadSerializer() )->fromRow( $row );
	}

	public function testRefusesARowWithAnUnknownLane(): void {
		$row         = ( new EventPayloadSerializer() )->toRow( $this->event( array() ), new DateTimeImmutable() );
		$row['lane'] = 'express';

		$this->expectException( UnexpectedValueException::class );
		( new EventPayloadSerializer() )->fromRow( $row );
	}

	/**
	 * An event.
	 *
	 * @param array<string, mixed>   $payload  Payload.
	 * @param EventLane              $lane     Lane.
	 * @param string|null            $key      Coalesce key.
	 * @param DateTimeImmutable|null $occurred When it happened.
	 */
	private function event( array $payload, EventLane $lane = EventLane::Critical, ?string $key = null, ?DateTimeImmutable $occurred = null ): DomainEvent {
		return new DomainEvent( 'store.profile.updated', '01920000-0000-7000-8000-000000000001', $occurred ?? new DateTimeImmutable( '2026-10-23 12:00:00.123', new DateTimeZone( 'UTC' ) ), 'store', '7', 3, new ActorRef( 'user', 12 ), 'req_abc', $payload, 2, $lane, $key );
	}
}
