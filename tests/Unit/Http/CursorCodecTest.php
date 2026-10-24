<?php
/**
 * Cursors round-trip and reject anything else (API-1, IDN-7).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Unit\Http;

use Mercatus\Core\Shared\Http\Cursor;
use PHPUnit\Framework\TestCase;

/**
 * URL-safe, and decoding gives back exactly what was encoded or nothing.
 */
final class CursorCodecTest extends TestCase {

	public function testRoundTripsAndIsUrlSafe(): void {
		$cursor = Cursor::encode( 'created_at', 'desc', '2026-10-24 10:00:00.000', 42 );

		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]+$/', $cursor );
		$this->assertSame( array( 'created_at', 'desc', '2026-10-24 10:00:00.000', 42 ), Cursor::decode( $cursor ) );
	}

	/**
	 * Strings that aren't cursors.
	 *
	 * @return array<string, array{string}>
	 */
	public static function notCursors(): array {
		$encode = static fn( mixed $data ): string => rtrim( strtr( base64_encode( (string) json_encode( $data ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode, WordPress.WP.AlternativeFunctions.json_encode_json_encode -- builds malformed cursors.

		return array(
			'not base64'        => array( 'not a cursor!' ),
			'not JSON'          => array( $encode( null ) . 'x' ),
			'too short'         => array( $encode( array( 'id', 'asc', 1 ) ) ),
			'bad direction'     => array( $encode( array( 'id', 'sideways', 1, 1 ) ) ),
			'object value'      => array( $encode( array( 'id', 'asc', array( 'x' ), 1 ) ) ),
			'string ID'         => array( $encode( array( 'id', 'asc', 1, '1' ) ) ),
			'injection in sort' => array( $encode( array( 'id; DROP TABLE x', 'asc', 1, 1 ) ) . '!' ),
			'too long'          => array( str_repeat( 'a', 513 ) ),
		);
	}

	/**
	 * Rejects what isn't a cursor.
	 *
	 * @dataProvider notCursors
	 * @param string $cursor Candidate.
	 */
	public function testRejectsWhatIsNotACursor( string $cursor ): void {
		$this->assertNull( Cursor::decode( $cursor ) );
	}
}
