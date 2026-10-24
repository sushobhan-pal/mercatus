<?php
/**
 * What the active modules provide, for UIs to feature-detect (ARCH-6).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Kernel\Features;

use Mercatus\Core\Identity\Application\FeatureSource;
use Mercatus\Core\Shared\Http\Schema;

/**
 * Served by GET /features and embedded in GET /me. Module versions are shown to administrators only.
 */
final readonly class FeatureManifest implements FeatureSource {

	/**
	 * Creates the manifest.
	 *
	 * @param array<string, string> $modules  Version by module ID, sorted by ID.
	 * @param list<string>          $features Feature names, sorted and unique.
	 */
	public function __construct( public array $modules, public array $features ) {}

	/**
	 * Whether an active module provides a feature.
	 *
	 * @param string $feature Feature name.
	 */
	public function has( string $feature ): bool {
		return in_array( $feature, $this->features, true );
	}

	/**
	 * The response body.
	 *
	 * @param bool $with_versions Whether to include module versions.
	 * @return array{modules: list<array{id: string, version?: string}>, features: list<string>}
	 */
	public function toArray( bool $with_versions ): array {
		$modules = array();
		foreach ( $this->modules as $id => $version ) {
			$modules[] = $with_versions ? array(
				'id'      => $id,
				'version' => $version,
			) : array( 'id' => $id );
		}

		return array(
			'modules'  => $modules,
			'features' => $this->features,
		);
	}

	public function schema(): array {
		return Schema::object(
			array(
				'modules'  => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'id'      => array(
								'type'     => 'string',
								'required' => true,
							),
							'version' => array( 'type' => 'string' ),
						),
					),
				),
				'features' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
			)
		);
	}
}
