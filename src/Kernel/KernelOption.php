<?php
/**
 * The kernel's own option (KER-1, KER-14).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Kernel;

use Mercatus\Core\Kernel\Modules\ModuleStateStore;

/**
 * `mercatus_kernel`, autoloaded and small, so boot needs no query of its own. It holds the admin's module
 * choices and the latest applied migration version of each module (KER-5).
 */
final class KernelOption implements ModuleStateStore {

	public const NAME = 'mercatus_kernel';

	public function states(): array {
		$modules = $this->read()['modules'] ?? array();

		return is_array( $modules ) ? array_map( 'boolval', array_filter( $modules, 'is_bool' ) ) : array();
	}

	public function save( array $states ): void {
		$option            = $this->read();
		$option['modules'] = $states;
		update_option( self::NAME, $option, true );
	}

	/**
	 * The latest applied migration version of each module.
	 *
	 * @return array<string, int>
	 */
	public function migrations(): array {
		$migrations = $this->read()['migrations'] ?? array();

		return is_array( $migrations ) ? array_map( 'intval', array_filter( $migrations, 'is_numeric' ) ) : array();
	}

	/**
	 * Remembers a module's latest applied migration version.
	 *
	 * @param string $module_id Module ID.
	 * @param int    $version   Latest applied version.
	 */
	public function recordMigration( string $module_id, int $version ): void {
		$option                   = $this->read();
		$migrations               = is_array( $option['migrations'] ?? null ) ? $option['migrations'] : array();
		$migrations[ $module_id ] = $version;
		$option['migrations']     = $migrations;
		update_option( self::NAME, $option, true );
	}

	/**
	 * When the outbox poller last ran, for the health report.
	 */
	public function lastPoll(): ?int {
		$time = $this->read()['last_poll'] ?? null;

		return is_int( $time ) ? $time : null;
	}

	/**
	 * Remembers when the outbox poller ran.
	 *
	 * @param int $time Unix time.
	 */
	public function recordPoll( int $time ): void {
		$option              = $this->read();
		$option['last_poll'] = $time;
		update_option( self::NAME, $option, true );
	}

	/**
	 * The option, created the first time it's missing so later boots find it among the autoloaded options.
	 *
	 * @return array<mixed>
	 */
	private function read(): array {
		$option = get_option( self::NAME, null );
		if ( null === $option ) {
			$option = array( 'modules' => array() );
			add_option( self::NAME, $option, '', true );
		}

		return is_array( $option ) ? $option : array();
	}
}
