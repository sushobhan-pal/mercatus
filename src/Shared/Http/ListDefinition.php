<?php
/**
 * One list route's allow-list (SEC-3, IDN-7).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Shared\Http;

use Mercatus\Core\Shared\Errors\DomainError;

/**
 * Which columns may sort the list and which fields may filter it, with their allowed values. Nothing else in
 * the query string reaches SQL (G-SEC-2). Sorting always breaks ties by ID.
 */
final readonly class ListDefinition {

	/**
	 * Creates the definition.
	 *
	 * @param array<string, string>       $sorts          Sortable columns and their type, int or string; the first is the default.
	 * @param array<string, list<string>> $filters        Filterable fields and their allowed values.
	 * @param int                         $defaultPerPage Items per page by default.
	 * @param int                         $maxPerPage     Most items per page.
	 */
	public function __construct( public array $sorts, public array $filters = array(), public int $defaultPerPage = 24, public int $maxPerPage = 100 ) {}

	/**
	 * The route arguments, so WordPress checks their shape and the OpenAPI file documents them.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function args(): array {
		$args = array(
			'orderby'  => array(
				'type'        => 'string',
				'enum'        => array_keys( $this->sorts ),
				'description' => 'Sort column.',
			),
			'order'    => array(
				'type'        => 'string',
				'enum'        => array( 'asc', 'desc' ),
				'description' => 'Sort direction.',
			),
			'per_page' => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'maximum'     => $this->maxPerPage,
				'description' => 'Items per page.',
			),
			'cursor'   => array(
				'type'        => 'string',
				'maxLength'   => 512,
				'description' => 'Where the previous page ended (next_cursor).',
			),
		);
		foreach ( $this->filters as $field => $values ) {
			$args[ $field ] = array(
				'type'        => 'string',
				'enum'        => $values,
				'description' => 'Filter by ' . $field . '.',
			);
		}

		return $args;
	}

	/**
	 * The validated request.
	 *
	 * @param array<mixed> $params Request parameters.
	 * @throws DomainError When a parameter isn't allowed (mercatus_invalid_list_parameter).
	 */
	public function parse( array $params ): ListRequest {
		$sort      = $params['orderby'] ?? (string) array_key_first( $this->sorts );
		$direction = $params['order'] ?? 'asc';
		$per_page  = $params['per_page'] ?? $this->defaultPerPage;
		if ( ! is_string( $sort ) || ! isset( $this->sorts[ $sort ] ) ) {
			throw self::invalid( 'orderby' );
		}
		if ( ! in_array( $direction, array( 'asc', 'desc' ), true ) ) {
			throw self::invalid( 'order' );
		}
		if ( ! is_numeric( $per_page ) || (int) $per_page < 1 || (int) $per_page > $this->maxPerPage ) {
			throw self::invalid( 'per_page' );
		}

		$filters = array();
		foreach ( $this->filters as $field => $values ) {
			if ( isset( $params[ $field ] ) ) {
				if ( ! is_string( $params[ $field ] ) || ! in_array( $params[ $field ], $values, true ) ) {
					throw self::invalid( $field );
				}
				$filters[ $field ] = $params[ $field ];
			}
		}

		$after_value = null;
		$after_id    = null;
		if ( isset( $params['cursor'] ) ) {
			$cursor = is_string( $params['cursor'] ) ? Cursor::decode( $params['cursor'] ) : null;
			if ( null === $cursor || $cursor[0] !== $sort || $cursor[1] !== $direction || ! $this->fits( $sort, $cursor[2] ) ) {
				throw self::invalid( 'cursor' );
			}
			list( , , $after_value, $after_id ) = $cursor;
		}

		return new ListRequest( $sort, $direction, (int) $per_page, $after_value, $after_id, $filters );
	}

	/**
	 * Whether a cursor value has the sort column's type.
	 *
	 * @param string     $sort  Sort column.
	 * @param int|string $value Value.
	 */
	private function fits( string $sort, int|string $value ): bool {
		return 'int' === $this->sorts[ $sort ] ? is_int( $value ) : is_string( $value );
	}

	/**
	 * The error for a parameter that isn't allowed.
	 *
	 * @param string $parameter Parameter name.
	 */
	private static function invalid( string $parameter ): DomainError {
		return DomainError::of( 'mercatus_invalid_list_parameter', array( 'parameter' => $parameter ) );
	}
}
