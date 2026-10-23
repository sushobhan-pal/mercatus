<?php
/**
 * An event as the outbox holds it (API-3).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Infrastructure\Outbox;

use Mercatus\Core\Shared\Events\DomainEvent;

/**
 * The row ID, the event and how many attempts have failed so far.
 */
final readonly class OutboxRecord {

	/**
	 * Creates the record.
	 *
	 * @param int         $id       Row ID.
	 * @param DomainEvent $event    Event.
	 * @param int         $attempts Failed attempts so far.
	 */
	public function __construct( public int $id, public DomainEvent $event, public int $attempts ) {}
}
