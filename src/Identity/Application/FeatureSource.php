<?php
/**
 * The feature manifest, as /me embeds it (ARCH-6, ARCH-9).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Identity\Application;

/**
 * The kernel's feature manifest implements it, so `/features` and `/me` share one shape.
 */
interface FeatureSource {

	/**
	 * Active modules and their features.
	 *
	 * @param bool $with_versions Whether to include module versions (administrators).
	 * @return array{modules: list<array{id: string, version?: string}>, features: list<string>}
	 */
	public function toArray( bool $with_versions ): array;

	/**
	 * The JSON Schema of toArray()'s result.
	 *
	 * @return array<string, mixed>
	 */
	public function schema(): array;
}
