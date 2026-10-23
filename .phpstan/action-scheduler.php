<?php
/**
 * Action Scheduler functions missing from the WooCommerce stubs, for PHPStan only. Never loaded at runtime.
 */

/**
 * @param array<mixed> $args Arguments.
 */
function as_schedule_recurring_action( int $timestamp, int $interval_in_seconds, string $hook, array $args = array(), string $group = '', bool $unique = false, int $priority = 10 ): int {
	return 0;
}
