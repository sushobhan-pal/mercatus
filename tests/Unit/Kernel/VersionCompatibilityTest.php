<?php
/**
 * Version constraints in module manifests (ARCH-6).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Unit\Kernel;

use InvalidArgumentException;
use Mercatus\Core\Kernel\Modules\VersionConstraint;
use PHPUnit\Framework\TestCase;

/**
 * The Composer syntax that manifests use for "core" and module requirements.
 */
final class VersionCompatibilityTest extends TestCase {

	/**
	 * Versions, constraints and whether they match.
	 *
	 * @return array<string, array{string, string, bool}>
	 */
	public static function cases(): array {
		return array(
			'exact'                      => array( '1.2.3', '1.2.3', true ),
			'exact, other patch'         => array( '1.2.4', '1.2.3', false ),
			'partial exact'              => array( '1.2.0', '1.2', true ),
			'caret'                      => array( '1.9.9', '^1.2', true ),
			'caret, below'               => array( '1.1.9', '^1.2', false ),
			'caret, next major'          => array( '2.0.0', '^1.2', false ),
			'caret on 0.x'               => array( '0.3.9', '^0.3', true ),
			'caret on 0.x, next minor'   => array( '0.4.0', '^0.3', false ),
			'caret on 0.0.x'             => array( '0.0.3', '^0.0.3', true ),
			'caret on 0.0.x, next patch' => array( '0.0.4', '^0.0.3', false ),
			'tilde on minor'             => array( '1.9.0', '~1.2', true ),
			'tilde on minor, next major' => array( '2.0.0', '~1.2', false ),
			'tilde on patch'             => array( '1.2.9', '~1.2.3', true ),
			'tilde on patch, next minor' => array( '1.3.0', '~1.2.3', false ),
			'wildcard'                   => array( '1.2.7', '1.2.*', true ),
			'wildcard, other minor'      => array( '1.3.0', '1.2.*', false ),
			'any'                        => array( '9.9.9', '*', true ),
			'range'                      => array( '1.5.0', '>=1.2 <2.0', true ),
			'range with a comma'         => array( '2.0.0', '>=1.2, <2.0', false ),
			'alternatives'               => array( '2.1.0', '^1.0 || ^2.0', true ),
			'not equal'                  => array( '1.0.0', '!=1.0.0', false ),
			'pre-release ignored'        => array( '0.1.0-dev', '^0.1', true ),
			'v prefix'                   => array( '1.0.0', '>=v1.0', true ),
		);
	}

	/**
	 * Checks one version against one constraint.
	 *
	 * @dataProvider cases
	 * @param string $version    Version.
	 * @param string $constraint Constraint.
	 * @param bool   $expected   Whether it should match.
	 */
	public function testMatchesComposerSemantics( string $version, string $constraint, bool $expected ): void {
		$this->assertTrue( VersionConstraint::isValid( $constraint ) );
		$this->assertSame( $expected, VersionConstraint::satisfies( $version, $constraint ) );
	}

	/**
	 * Constraints that can't be parsed.
	 *
	 * @return array<string, array{string}>
	 */
	public static function invalidConstraints(): array {
		return array(
			'empty'             => array( '' ),
			'empty alternative' => array( ' || ^1' ),
			'words'             => array( 'latest' ),
			'bare caret'        => array( '^' ),
			'bad bound'         => array( '>=x' ),
			'four-part version' => array( '1.2.3.4' ),
		);
	}

	/**
	 * Refuses a constraint it can't parse.
	 *
	 * @dataProvider invalidConstraints
	 * @param string $constraint Constraint.
	 */
	public function testRefusesUnparseableConstraints( string $constraint ): void {
		$this->assertFalse( VersionConstraint::isValid( $constraint ) );
		$this->expectException( InvalidArgumentException::class );
		VersionConstraint::satisfies( '1.0.0', $constraint );
	}
}
