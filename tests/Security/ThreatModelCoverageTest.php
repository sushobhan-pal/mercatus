<?php
/**
 * The threat model stays tied to real tests and rules (GW-9).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Security;

use PHPUnit\Framework\TestCase;

/**
 * Reads docs/security/threat-model.md and the master document, and checks the rules the threat model states
 * in "How this document is kept honest".
 */
final class ThreatModelCoverageTest extends TestCase {

	private const ROOT = __DIR__ . '/../..';

	private const STRIDE = array( 'S', 'T', 'R', 'I', 'D', 'E' );

	private const OWASP = array( 'API1', 'API2', 'API3', 'API4', 'API5', 'API6', 'API7', 'API8', 'API9', 'API10' );

	/**
	 * Prefixes of IDs local to the threat model (threats, accepted risks), not master rules.
	 */
	private const LOCAL_ID_PREFIXES = array( 'TM', 'AR' );

	/**
	 * Threat model text.
	 *
	 * @var string
	 */
	private static string $model = '';

	/**
	 * Master document text.
	 *
	 * @var string
	 */
	private static string $master = '';

	public static function setUpBeforeClass(): void {
		self::$model  = (string) file_get_contents( self::ROOT . '/docs/security/threat-model.md' );
		self::$master = (string) file_get_contents( self::ROOT . '/mercatus_master_architecture_and_testing_constitution.md' );
	}

	public function testEveryThreatIsWellFormedAndNamesATest(): void {
		$threats = $this->threats();

		$this->assertNotEmpty( $threats );
		foreach ( $threats as $id => $threat ) {
			$this->assertNotSame( array(), $threat['tests'], "$id names no test." );
			$this->assertSame( array(), array_diff( $threat['stride'], self::STRIDE ), "$id has an unknown STRIDE category." );
			$this->assertSame( array(), array_diff( $threat['owasp'], self::OWASP, array( '—' ) ), "$id has an unknown OWASP item." );
		}
	}

	public function testEveryTestIsInTheModuleMatrixOrAddedByTheModel(): void {
		$known   = array_merge( $this->moduleMatrixTests(), array_keys( $this->addedTests() ) );
		$unknown = array();
		foreach ( $this->threats() as $id => $threat ) {
			foreach ( array_diff( $threat['tests'], $known ) as $test ) {
				$unknown[] = "$id: $test";
			}
		}

		$this->assertSame( array(), $unknown, 'Name a TEST-3 test, or add the test to "Tests this model adds".' );
	}

	public function testAddedTestsAreUsedNewAndAdoptedByTheirOwners(): void {
		$matrix  = $this->moduleMatrixTests();
		$threats = $this->threats();

		foreach ( $this->addedTests() as $test => $added ) {
			$users = array_keys( array_filter( $threats, static fn( array $threat ): bool => in_array( $test, $threat['tests'], true ) ) );

			$this->assertNotContains( $test, $matrix, "$test is in TEST-3 now; remove it from the added tests." );
			$this->assertSame( $users, $added['threats'], "$test lists different threats from the ones that use it." );

			$decision_file = self::ROOT . '/decisions/' . $added['owner'] . '.md';
			if ( 1 === preg_match( '/^(core|pro)\.[a-z][a-z0-9-]*$/', $added['owner'] ) && is_file( $decision_file ) ) {
				$this->assertStringContainsString( $test, (string) file_get_contents( $decision_file ), "decisions/{$added['owner']}.md must adopt $test." );
			}
		}
	}

	public function testMitigationsCiteRulesTheMasterDocumentDefines(): void {
		foreach ( $this->threats() as $id => $threat ) {
			preg_match_all( '/\b(?:G-)?[A-Z]+-\d+\b/', $threat['mitigation'], $matches );
			$rules = array_filter( $matches[0], static fn( string $rule ): bool => ! in_array( strstr( $rule, '-', true ), self::LOCAL_ID_PREFIXES, true ) );

			$this->assertNotSame( array(), $rules, "$id cites no rule." );
			foreach ( $rules as $rule ) {
				$defined = str_contains( self::$master, '**' . $rule . ' ' ) || str_contains( self::$master, '**' . $rule . '**' );
				$this->assertTrue( $defined, "$id cites $rule, which the master document doesn't define." );
			}
		}
	}

	public function testCoversEveryActorSurfaceStrideCategoryAndOwaspItem(): void {
		$threats  = $this->threats();
		$actors   = implode( ' ', array_column( $threats, 'actors' ) );
		$surfaces = $this->surfaces();
		$cited    = array_unique( array_merge( ...array_column( $threats, 'surfaces' ) ) );

		foreach ( $this->securityActors() as $actor ) {
			$this->assertStringContainsString( "`$actor`", $actors, "No threat involves $actor." );
		}
		$this->assertSame( array(), array_diff( $cited, $surfaces ), 'A threat cites a surface the Surfaces table lacks.' );
		$this->assertSame( array(), array_diff( $surfaces, $cited ), 'A surface has no threat.' );
		$this->assertSame( array(), array_diff( self::STRIDE, ...array_column( $threats, 'stride' ) ), 'A STRIDE category has no threat.' );
		$this->assertSame( array(), array_diff( self::OWASP, ...array_column( $threats, 'owasp' ) ), 'An OWASP API Top 10 item has no threat.' );
	}

	/**
	 * Threat rows by ID.
	 *
	 * @return array<string, array{stride: list<string>, owasp: list<string>, actors: string, surfaces: list<string>, mitigation: string, tests: list<string>}>
	 */
	private function threats(): array {
		$threats = array();
		foreach ( $this->rows( '/^TM-\d+$/' ) as $cells ) {
			$this->assertCount( 8, $cells, $cells[0] . ' needs 8 columns.' );
			$this->assertArrayNotHasKey( $cells[0], $threats, $cells[0] . ' is used twice.' );
			preg_match_all( '/`([A-Z]\w*Test)`/', $cells[7], $tests );
			$threats[ $cells[0] ] = array(
				'stride'     => $this->items( $cells[1] ),
				'owasp'      => $this->items( $cells[2] ),
				'actors'     => $cells[3],
				'surfaces'   => $this->items( $cells[4] ),
				'mitigation' => $cells[6],
				'tests'      => $tests[1],
			);
		}

		return $threats;
	}

	/**
	 * Rows of "Tests this model adds", by test name.
	 *
	 * @return array<string, array{owner: string, threats: list<string>}>
	 */
	private function addedTests(): array {
		$added = array();
		foreach ( $this->rows( '/^`\w+Test`$/' ) as $cells ) {
			$added[ trim( $cells[0], '`' ) ] = array(
				'owner'   => $cells[1],
				'threats' => $this->items( $cells[2] ),
			);
		}

		return $added;
	}

	/**
	 * Surface names from the Surfaces table.
	 *
	 * @return list<string>
	 */
	private function surfaces(): array {
		return array_map( static fn( array $cells ): string => trim( $cells[0], '*' ), $this->rows( '/^\*\*[^*]+\*\*$/' ) );
	}

	/**
	 * Test class names in the master document's TEST-3 section, including the cross-cutting tests.
	 *
	 * @return list<string>
	 */
	private function moduleMatrixTests(): array {
		$start = (int) strpos( self::$master, '- **TEST-3 ' );
		$end   = (int) strpos( self::$master, '- **TEST-4 ', $start );
		preg_match_all( '/`([A-Z]\w*Test)`/', substr( self::$master, $start, $end - $start ), $matches );

		$this->assertGreaterThan( $start, $end, 'TEST-3 not found in the master document.' );

		return array_values( array_unique( $matches[1] ) );
	}

	/**
	 * Actor types listed in SEC-1.
	 *
	 * @return list<string>
	 */
	private function securityActors(): array {
		preg_match( '/^- \*\*SEC-1 .*$/m', self::$master, $line );
		preg_match_all( '/`([a-z_]+)`/', $line[0] ?? '', $matches );

		$this->assertNotSame( array(), $matches[1], 'SEC-1 actors not found in the master document.' );

		return $matches[1];
	}

	/**
	 * Cells of the threat model's table rows whose first cell matches a pattern.
	 *
	 * @param string $first_cell Pattern for the first cell.
	 * @return list<list<string>>
	 */
	private function rows( string $first_cell ): array {
		$rows = array();
		foreach ( explode( "\n", self::$model ) as $line ) {
			if ( ! str_starts_with( $line, '|' ) ) {
				continue;
			}
			$cells = array_map( 'trim', explode( '|', trim( $line, " |\t" ) ) );
			if ( 1 === preg_match( $first_cell, $cells[0] ) ) {
				$rows[] = $cells;
			}
		}

		return $rows;
	}

	/**
	 * Comma-separated items of a cell.
	 *
	 * @param string $cell Cell text.
	 * @return list<string>
	 */
	private function items( string $cell ): array {
		return array_values( array_filter( array_map( 'trim', explode( ',', $cell ) ), static fn( string $item ): bool => '' !== $item ) );
	}
}
