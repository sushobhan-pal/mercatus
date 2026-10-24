<?php
/**
 * JSON Schema helpers for route definitions (DRY-2).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Shared\Http;

/**
 * Draft-04, which WordPress validates; the OpenAPI generator turns it into 2020-12.
 */
final class Schema {

	/**
	 * An object schema whose properties are all required.
	 *
	 * @param array<string, array<string, mixed>> $properties Properties.
	 * @return array<string, mixed>
	 */
	public static function object( array $properties ): array {
		foreach ( $properties as $name => $property ) {
			$properties[ $name ] = $property + array( 'required' => true );
		}

		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'type'       => 'object',
			'properties' => $properties,
		);
	}
}
