<?php
/**
 * AuditLog that writes in the current transaction (API-3, KER-9).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Infrastructure\Audit;

use Mercatus\Core\Infrastructure\Database\UnitOfWork;
use Mercatus\Core\Shared\Actor\ExecutionContext;
use Mercatus\Core\Shared\Audit\AuditLog;
use Mercatus\Core\Shared\Clock\Clock;

/**
 * Buffers entries with the current actor and request; the transaction runner writes them before commit.
 */
final class BufferedAuditLog implements AuditLog {

	/**
	 * Creates the log.
	 *
	 * @param UnitOfWork       $unit    Transaction buffer.
	 * @param ExecutionContext $context Actor and request.
	 * @param Clock            $clock   Current time.
	 */
	public function __construct( private UnitOfWork $unit, private ExecutionContext $context, private Clock $clock ) {}

	public function record( string $action, string $resource_type, int|string $resource_id, array $changes = array() ): void {
		$this->unit->addAudit( new AuditRecord( $this->context->actor(), $action, $resource_type, (string) $resource_id, $this->context->requestId(), $changes, $this->clock->now() ) );
	}
}
