<?php
/**
 * One transaction over Mercatus tables (ARCH-10, API-3).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Shared\Persistence;

/**
 * Runs work in a database transaction that also writes the events and audit entries the work recorded, then
 * hands critical events to their subscribers after commit. Wraps only wp_mercatus_* writes, never WooCommerce
 * CRUD (ARCH-10). Runs may nest; only the outermost commits.
 */
interface TransactionRunner {

	/**
	 * Runs work in a transaction.
	 *
	 * @template T
	 * @param callable(): T $work Work.
	 * @return T
	 * @throws \Throwable Whatever the work throws, after rolling back.
	 */
	public function run( callable $work ): mixed;
}
