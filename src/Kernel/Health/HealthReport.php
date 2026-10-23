<?php
/**
 * What GET /health reports (PLAT-4, PLAT-8, KER-11).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Kernel\Health;

use Mercatus\Core\Contracts\Cache\CoordinationPort;
use Mercatus\Core\Contracts\Health\HealthCheck;
use Mercatus\Core\Contracts\Health\HealthResult;
use Mercatus\Core\Contracts\Health\HealthStatus;
use Mercatus\Core\Infrastructure\Outbox\OutboxStore;
use Mercatus\Core\Kernel\Http\WordPressRouteRegistrar;
use Mercatus\Core\Kernel\Kernel;
use Mercatus\Core\Kernel\KernelOption;
use Mercatus\Core\Kernel\Modules\ModuleManifestValidator;
use Throwable;

/**
 * The kernel's own checks (cache and rate limiting, modules, migrations, outbox, poller, routes) plus every
 * active module's checks. The overall status is the worst one.
 */
final class HealthReport {

	private const BACKLOG_MINUTES = 15;

	private const POLLER_MINUTES = 10;

	/**
	 * Creates the report.
	 *
	 * @param Kernel                  $kernel       Booted kernel.
	 * @param CoordinationPort        $coordination Whether a persistent cache backs counters and locks.
	 * @param OutboxStore             $outbox       Outbox numbers.
	 * @param KernelOption            $option       Poller heartbeat.
	 * @param WordPressRouteRegistrar $routes       Refused routes.
	 */
	public function __construct(
		private Kernel $kernel,
		private CoordinationPort $coordination,
		private OutboxStore $outbox,
		private KernelOption $option,
		private WordPressRouteRegistrar $routes,
	) {}

	/**
	 * Runs every check.
	 *
	 * @return array{status: string, checks: list<array{id: string, status: string, code: string, details: array<string, mixed>}>}
	 */
	public function build(): array {
		$resolution = $this->kernel->resolution();
		$pending    = array_keys( array_filter( $resolution->rejected, static fn( string $reason ): bool => ModuleManifestValidator::PENDING_MIGRATIONS === $reason ) );
		$rejected   = array_diff_key( $resolution->rejected, array_flip( $pending ) );

		$results = array(
			'kernel.cache'      => $this->coordination->isAvailable()
				? new HealthResult( HealthStatus::Ok, 'mercatus_persistent_cache', array( 'rate_limiting' => true ) )
				: new HealthResult( HealthStatus::Warning, 'mercatus_no_persistent_cache', array( 'rate_limiting' => false ) ),
			'kernel.modules'    => array() === $rejected
				? new HealthResult( HealthStatus::Ok, 'mercatus_modules_loaded' )
				: new HealthResult( HealthStatus::Error, 'mercatus_modules_rejected', array( 'rejected' => $rejected ) ),
			'kernel.migrations' => array() === $pending
				? new HealthResult( HealthStatus::Ok, 'mercatus_migrations_current' )
				: new HealthResult( HealthStatus::Error, 'mercatus_migrations_pending', array( 'modules' => $pending ) ),
			'kernel.routes'     => array() === $this->routes->refused()
				? new HealthResult( HealthStatus::Ok, 'mercatus_routes_registered' )
				: new HealthResult( HealthStatus::Error, 'mercatus_routes_refused', array( 'routes' => $this->routes->refused() ) ),
			'kernel.poller'     => $this->poller(),
		);
		if ( ! in_array( 'core.kernel', $pending, true ) ) {
			$results['kernel.outbox'] = $this->outbox();
		}
		foreach ( $resolution->active as $manifest ) {
			foreach ( $manifest->healthChecks as $class_name ) {
				$check = $this->kernel->container()->get( $class_name );
				if ( $check instanceof HealthCheck ) {
					$results[ $check->id() ] = $this->run( $check );
				}
			}
		}

		$checks = array();
		$worst  = HealthStatus::Ok;
		foreach ( $results as $id => $result ) {
			$checks[] = array(
				'id'      => $id,
				'status'  => $result->status->value,
				'code'    => $result->code,
				'details' => $result->details,
			);
			$worst    = self::worse( $worst, $result->status );
		}

		return array(
			'status' => $worst->value,
			'checks' => $checks,
		);
	}

	/**
	 * Whether the outbox is keeping up.
	 */
	private function outbox(): HealthResult {
		$numbers = $this->outbox->health();
		$oldest  = null === $numbers['oldest_pending'] ? null : strtotime( $numbers['oldest_pending'] . ' UTC' );
		$behind  = null !== $oldest && false !== $oldest && time() - $oldest > self::BACKLOG_MINUTES * MINUTE_IN_SECONDS;

		return $numbers['failed'] > 0 || $behind
			? new HealthResult( HealthStatus::Warning, 'mercatus_outbox_backlog', $numbers )
			: new HealthResult( HealthStatus::Ok, 'mercatus_outbox_current', $numbers );
	}

	/**
	 * Whether the poller ran recently.
	 */
	private function poller(): HealthResult {
		$last = $this->option->lastPoll();

		return null !== $last && time() - $last <= self::POLLER_MINUTES * MINUTE_IN_SECONDS
			? new HealthResult( HealthStatus::Ok, 'mercatus_poller_running', array( 'last_poll' => $last ) )
			: new HealthResult( HealthStatus::Warning, 'mercatus_poller_stalled', array( 'last_poll' => $last ) );
	}

	/**
	 * Runs a module's check; a check that throws is reported, never fatal.
	 *
	 * @param HealthCheck $check Check.
	 */
	private function run( HealthCheck $check ): HealthResult {
		try {
			return $check->check();
		} catch ( Throwable ) {
			return new HealthResult( HealthStatus::Error, 'mercatus_health_check_failed' );
		}
	}

	/**
	 * The worse of two statuses.
	 *
	 * @param HealthStatus $a Status.
	 * @param HealthStatus $b Status.
	 */
	private static function worse( HealthStatus $a, HealthStatus $b ): HealthStatus {
		$rank = array(
			HealthStatus::Ok->value      => 0,
			HealthStatus::Warning->value => 1,
			HealthStatus::Error->value   => 2,
		);

		return $rank[ $b->value ] > $rank[ $a->value ] ? $b : $a;
	}
}
