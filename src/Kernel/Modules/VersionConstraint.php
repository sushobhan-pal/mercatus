<?php
/**
 * Version constraints in module manifests (ARCH-6).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Kernel\Modules;

use InvalidArgumentException;

/**
 * The Composer syntax manifests use: exact versions, comparisons (>=, >, <=, <, =, !=), caret (^1.2), tilde
 * (~1.2), wildcards (1.2.*, *), AND with spaces or commas, and OR with ||. Pre-release suffixes such as -dev
 * are ignored, so 0.1.0-dev counts as 0.1.0.
 */
final class VersionConstraint {

	private const VERSION = '/^v?(\d+)(?:\.(\d+))?(?:\.(\d+))?(?:-[0-9A-Za-z.-]+)?$/';

	/**
	 * Whether a version satisfies a constraint.
	 *
	 * @param string $version    Version such as 1.4.0.
	 * @param string $constraint Constraint such as ^1.2 || ~2.0.
	 * @throws InvalidArgumentException When the version or constraint can't be parsed.
	 */
	public static function satisfies( string $version, string $constraint ): bool {
		$actual       = self::parse( $version );
		$alternatives = self::compile( $constraint );

		foreach ( $alternatives as $comparisons ) {
			$matches = true;
			foreach ( $comparisons as [ $operator, $bound ] ) {
				$matches = $matches && self::compare( $actual, $operator, $bound );
			}
			if ( $matches ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a constraint can be parsed.
	 *
	 * @param string $constraint Constraint.
	 */
	public static function isValid( string $constraint ): bool {
		try {
			self::compile( $constraint );
			return true;
		} catch ( InvalidArgumentException ) {
			return false;
		}
	}

	/**
	 * Alternatives (OR) of comparisons (AND).
	 *
	 * @param string $constraint Constraint.
	 * @return list<list<array{string, array{int, int, int}}>>
	 * @throws InvalidArgumentException When a term can't be parsed.
	 */
	private static function compile( string $constraint ): array {
		$alternatives = array();
		foreach ( explode( '||', $constraint ) as $alternative ) {
			$terms = preg_split( '/[\s,]+/', trim( $alternative ), -1, PREG_SPLIT_NO_EMPTY );
			if ( false === $terms || array() === $terms ) {
				throw new InvalidArgumentException( 'A version constraint has an empty alternative.' );
			}
			$comparisons = array();
			foreach ( $terms as $term ) {
				array_push( $comparisons, ...self::term( $term ) );
			}
			$alternatives[] = $comparisons;
		}

		return $alternatives;
	}

	/**
	 * Comparisons for one term.
	 *
	 * @param string $term Term such as ^1.2, >=2.0 or 1.4.*.
	 * @return list<array{string, array{int, int, int}}>
	 * @throws InvalidArgumentException When the term can't be parsed.
	 */
	private static function term( string $term ): array {
		if ( '*' === $term ) {
			return array();
		}
		if ( 1 === preg_match( '/^(\d+)(?:\.(\d+))?\.\*$/', $term, $wildcard, PREG_UNMATCHED_AS_NULL ) ) {
			$given = null === $wildcard[2] ? 1 : 2;
			$lower = array( (int) $wildcard[1], (int) $wildcard[2], 0 );
			return array( array( '>=', $lower ), array( '<', self::bump( $lower, $given - 1 ) ) );
		}
		if ( 1 === preg_match( '/^(\^|~|>=|<=|>|<|!=|=)?(.+)$/', $term, $parts ) ) {
			[ $lower, $given ] = self::partial( $parts[2] );
			return match ( $parts[1] ) {
				'^'     => array( array( '>=', $lower ), array( '<', self::bump( $lower, self::caretIndex( $lower, $given ) ) ) ),
				'~'     => array( array( '>=', $lower ), array( '<', self::bump( $lower, max( 0, $given - 2 ) ) ) ),
				''      => array( array( '=', $lower ) ),
				default => array( array( $parts[1], $lower ) ),
			};
		}

		throw new InvalidArgumentException( 'A version constraint term cannot be parsed.' );
	}

	/**
	 * Parses a full or partial version.
	 *
	 * @param string $version Version.
	 * @return array{int, int, int}
	 * @throws InvalidArgumentException When the version can't be parsed.
	 */
	private static function parse( string $version ): array {
		return self::partial( $version )[0];
	}

	/**
	 * Parses a version that may give only its major or minor part.
	 *
	 * @param string $version Version.
	 * @return array{array{int, int, int}, int} The version padded with zeros, and how many parts were given.
	 * @throws InvalidArgumentException When the version can't be parsed.
	 */
	private static function partial( string $version ): array {
		if ( 1 !== preg_match( self::VERSION, $version, $parts, PREG_UNMATCHED_AS_NULL ) ) {
			throw new InvalidArgumentException( 'A version cannot be parsed.' );
		}
		$given = 1 + (int) ( null !== $parts[2] ) + (int) ( null !== $parts[3] );

		return array( array( (int) $parts[1], (int) $parts[2], (int) $parts[3] ), $given );
	}

	/**
	 * Which part a caret constraint may not change: the first non-zero part, or the last part given.
	 *
	 * @param array{int, int, int} $version Lower bound.
	 * @param int                  $given   Parts given.
	 */
	private static function caretIndex( array $version, int $given ): int {
		for ( $index = 0; $index < $given; $index++ ) {
			if ( 0 !== $version[ $index ] ) {
				return $index;
			}
		}

		return $given - 1;
	}

	/**
	 * Increments one part and zeroes the parts after it.
	 *
	 * @param array{int, int, int} $version Version.
	 * @param int                  $index   Part to increment.
	 * @return array{int, int, int}
	 */
	private static function bump( array $version, int $index ): array {
		return match ( $index ) {
			0       => array( $version[0] + 1, 0, 0 ),
			1       => array( $version[0], $version[1] + 1, 0 ),
			default => array( $version[0], $version[1], $version[2] + 1 ),
		};
	}

	/**
	 * Compares a version with a bound.
	 *
	 * @param array{int, int, int} $version  Version.
	 * @param string               $operator Operator.
	 * @param array{int, int, int} $bound    Bound.
	 */
	private static function compare( array $version, string $operator, array $bound ): bool {
		$order = $version <=> $bound;

		return match ( $operator ) {
			'>='    => $order >= 0,
			'>'     => $order > 0,
			'<='    => $order <= 0,
			'<'     => $order < 0,
			'!='    => 0 !== $order,
			default => 0 === $order,
		};
	}
}
