<?php
/**
 * Builds the feature manifest from the active modules (ARCH-6).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Kernel\Features;

use Mercatus\Core\Contracts\Module\ModuleManifest;

/**
 * Only active modules count, so a switched-off module's features disappear with it.
 */
final class FeatureManifestCompiler {

	/**
	 * Compiles the manifest.
	 *
	 * @param list<ModuleManifest> $active Active modules.
	 */
	public function compile( array $active ): FeatureManifest {
		$modules  = array();
		$features = array();
		foreach ( $active as $manifest ) {
			$modules[ $manifest->id ] = $manifest->version;
			array_push( $features, ...$manifest->provides );
		}
		ksort( $modules );
		$features = array_values( array_unique( $features ) );
		sort( $features );

		return new FeatureManifest( $modules, $features );
	}
}
