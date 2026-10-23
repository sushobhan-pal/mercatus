<?php
/**
 * Soft expiry for hot cache entries (PLAT-3, KER-6).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Unit\Cache;

use DateTimeImmutable;
use Mercatus\Core\Shared\Cache\SoftExpiry;
use PHPUnit\Framework\TestCase;

/**
 * When a value turns stale, how long the backend keeps it, and which stored values are envelopes.
 */
final class SoftExpiryStampedeCalculatorTest extends TestCase {

	public function testAValueTurnsStaleAtItsSoftExpiry(): void {
		$now      = new DateTimeImmutable( '2026-10-23 12:00:00' );
		$envelope = SoftExpiry::wrap( 'value', 60, $now );

		$this->assertTrue( SoftExpiry::isFresh( $envelope, $now->modify( '+59 seconds' ) ) );
		$this->assertFalse( SoftExpiry::isFresh( $envelope, $now->modify( '+60 seconds' ) ) );
	}

	public function testAPlainValueNeverTurnsStale(): void {
		$this->assertTrue( SoftExpiry::isFresh( SoftExpiry::plain( false ), new DateTimeImmutable( '+10 years' ) ) );
	}

	public function testTheBackendKeepsAValueFiveTimesLongerThanItStaysFresh(): void {
		$this->assertSame( 300, SoftExpiry::hardTtl( 60 ) );
	}

	/**
	 * Stored values that aren't envelopes.
	 *
	 * @return array<string, array{mixed}>
	 */
	public static function notEnvelopes(): array {
		return array(
			'false'          => array( false ),
			'scalar'         => array( 'value' ),
			'missing expiry' => array( array( 'v' => 1 ) ),
			'string expiry'  => array(
				array(
					'v' => 1,
					's' => '0',
				),
			),
			'extra key'      => array(
				array(
					'v' => 1,
					's' => 0,
					'x' => 1,
				),
			),
		);
	}

	/**
	 * Recognises only real envelopes, so other data under a Mercatus key reads as a miss.
	 *
	 * @dataProvider notEnvelopes
	 * @param mixed $stored Stored value.
	 */
	public function testRecognisesOnlyEnvelopes( mixed $stored ): void {
		$this->assertFalse( SoftExpiry::isEnvelope( $stored ) );
	}

	public function testRecognisesAnEnvelopeReadBackFromABackend(): void {
		// Backends serialize what they store.
		$this->assertTrue( SoftExpiry::isEnvelope( unserialize( serialize( SoftExpiry::plain( null ) ) ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- simulates a backend round trip on trusted data.
	}
}
