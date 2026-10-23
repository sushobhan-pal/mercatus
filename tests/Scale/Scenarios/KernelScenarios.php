<?php
/**
 * The kernel's scale scenarios (core.kernel §9, PLAT-10).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Scale\Scenarios;

use Mercatus\Core\Contracts\Cache\CachePort;
use Mercatus\Core\Infrastructure\Outbox\OutboxWorker;
use Mercatus\Core\Kernel\Health\HealthReport;
use Mercatus\Core\Kernel\Kernel;
use Mercatus\Core\Shared\Events\EventPublisher;
use Mercatus\Core\Shared\Persistence\TransactionRunner;
use Mercatus\Core\Tests\Scale\Support\ScaleScenario;
use RuntimeException;
use WP_REST_Request;

/**
 * Boot, the feature manifest and warm cache reads run no queries of their own. Publishing an event costs its
 * transaction (3) plus the committing request's claim, read and finish (3); an idle poll costs one claim per
 * lane; the health report reads the outbox once. None of them grows with the data set.
 */
final class KernelScenarios {

	/**
	 * The scenarios.
	 *
	 * @return list<ScaleScenario>
	 * @throws RuntimeException When the site's kernel didn't boot.
	 */
	public static function all(): array {
		$kernel   = Kernel::instance() ?? throw new RuntimeException( 'The kernel did not boot on the scale site.' );
		$services = $kernel->container();
		$cache    = $services->get( CachePort::class );
		$runner   = $services->get( TransactionRunner::class );
		$events   = $services->get( EventPublisher::class );
		$worker   = $services->get( OutboxWorker::class );
		$health   = $services->get( HealthReport::class );

		return array(
			new ScaleScenario(
				'kernel.boot',
				0,
				static function (): void {
					Kernel::create( MERCATUS_VERSION )->start();
				}
			),
			new ScaleScenario(
				'features.read',
				0,
				static function (): void {
					wp_set_current_user( 0 );
					rest_do_request( new WP_REST_Request( 'GET', '/mercatus/v1/features' ) );
				}
			),
			new ScaleScenario( 'cache.warm_read', 0, static fn(): mixed => $cache->remember( 'mercatus_scale', 'probe', 60, static fn(): string => 'value' ) ),
			new ScaleScenario(
				'outbox.publish',
				6,
				static fn(): mixed => $runner->run( static fn() => $events->publish( 'kernel.scale.probe', 'scale', 1, 0, array() ) )
			),
			new ScaleScenario( 'outbox.idle_poll', 2, static fn(): mixed => $worker->work() ),
			new ScaleScenario( 'health.report', 1, static fn(): array => $health->build() ),
		);
	}
}
