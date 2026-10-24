<?php
/**
 * Setting values in WordPress options (KER-4).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Kernel\Settings;

/**
 * One autoloaded option per module, `mercatus_settings_{module id}`, created when first read so that later
 * boots read it without a query (PLAT-4 allows small autoloaded options).
 */
final class OptionSettingsStore implements SettingsStore {

	public function load( string $module_id ): array {
		$values = get_option( self::name( $module_id ), null );
		if ( null === $values ) {
			$values = array();
			add_option( self::name( $module_id ), $values, '', true );
		}

		return is_array( $values ) ? $values : array();
	}

	public function save( string $module_id, array $values ): void {
		update_option( self::name( $module_id ), $values, true );
	}

	/**
	 * Option name for a module, for example mercatus_settings_core_kernel.
	 *
	 * @param string $module_id Module ID.
	 */
	public static function name( string $module_id ): string {
		return 'mercatus_settings_' . str_replace( array( '.', '-' ), '_', $module_id );
	}
}
