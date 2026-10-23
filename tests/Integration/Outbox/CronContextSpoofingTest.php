<?php
/**
 * Requests can't pose as the worker (TEST-3 row 3, SEC-1, TM-39).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Integration\Outbox;

use Mercatus\Core\Kernel\Kernel;
use Mercatus\Core\Shared\Actor\ExecutionContext;
use RuntimeException;

/**
 * Request input never makes the actor the system actor, and the poller hook does nothing unless Action
 * Scheduler is executing it.
 */
final class CronContextSpoofingTest extends OutboxIntegrationTestCase {

	public function tear_down(): void {
		unset( $_GET['doing_wp_cron'], $_REQUEST['doing_wp_cron'], $_SERVER['HTTP_X_MERCATUS_SYSTEM'] );
		parent::tear_down();
	}

	public function testRequestInputCannotMakeTheActorSystem(): void {
		$_GET['doing_wp_cron']             = '1';
		$_REQUEST['doing_wp_cron']         = '1';
		$_SERVER['HTTP_X_MERCATUS_SYSTEM'] = '1';

		$this->assertFalse( $this->context->actor()->isSystem() );
	}

	public function testOnlyRunAsSystemIsTheSystemActor(): void {
		$this->assertTrue( $this->context->runAsSystem( fn(): bool => $this->context->actor()->isSystem() ) );

		$this->swallow(
			fn() => $this->context->runAsSystem(
				static function (): void {
					throw new RuntimeException( 'The job failed.' );
				}
			)
		);

		$this->assertFalse( $this->context->actor()->isSystem() );
	}

	public function testThePollerRunsOnlyInsideActionScheduler(): void {
		// The site's kernel, booted on plugins_loaded, owns the poller hook.
		$kernel = Kernel::instance();
		$this->assertNotNull( $kernel );
		$this->store->insertMany( array( $this->event( 'cron' ) ), $this->clock->now() );

		do_action( Kernel::POLL_HOOK ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- mercatus_outbox_poll.
		$this->assertSame( 'pending', $this->status( 'test-cron' ), 'A request firing the hook does nothing.' );
		$this->assertNull( $kernel->poll() );

		$actor = null;
		$spy   = static function () use ( $kernel, &$actor ): void {
			$actor = $kernel->container()->get( ExecutionContext::class )->actor();
		};
		add_action( 'mercatus_event', $spy );
		// Plays Action Scheduler executing the poller.
		do_action( 'action_scheduler_begin_execute', 1, 'WP Cron' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Action Scheduler's hook.
		do_action( Kernel::POLL_HOOK ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- mercatus_outbox_poll.
		do_action( 'action_scheduler_after_execute', 1, null, 'WP Cron' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Action Scheduler's hook.
		remove_action( 'mercatus_event', $spy );

		$this->assertSame( 'processed', $this->status( 'test-cron' ) );
		$this->assertTrue( null !== $actor && $actor->isSystem() );
		$this->assertNull( $kernel->poll(), 'Outside the scheduler again.' );
	}
}
