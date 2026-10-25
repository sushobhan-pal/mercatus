<?php
/**
 * Encrypted secrets (SEC-6).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Unit\Infrastructure;

use Mercatus\Core\Infrastructure\Crypto\SecretBox;
use Mercatus\Core\Shared\Errors\DomainError;
use PHPUnit\Framework\TestCase;

/**
 * A sealed secret opens with its key only, never shows its plain text, and sealing without a key is refused.
 */
final class SecretBoxTest extends TestCase {

	/**
	 * 32 bytes of k, base64-encoded.
	 */
	private const KEY = 'a2tra2tra2tra2tra2tra2tra2tra2tra2tra2tra2s=';

	/**
	 * 32 bytes of x, base64-encoded.
	 */
	private const OTHER_KEY = 'eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHg=';

	public function testASealedSecretOpensWithItsKeyOnly(): void {
		$box    = new SecretBox( self::KEY );
		$sealed = $box->seal( 'AIza-test-key' );

		$this->assertStringStartsWith( 'v1:', $sealed );
		$this->assertStringNotContainsString( 'AIza', $sealed );
		$this->assertNotSame( $sealed, $box->seal( 'AIza-test-key' ), 'A fresh nonce each time.' );
		$this->assertSame( 'AIza-test-key', $box->open( $sealed ) );
		$this->assertNull( ( new SecretBox( self::OTHER_KEY ) )->open( $sealed ) );
		$this->assertNull( $box->open( 'plain text' ) );
	}

	public function testSealingWithoutAKeyIsRefused(): void {
		$box = new SecretBox( 'too-short' );
		$this->assertFalse( $box->available() );

		$this->expectException( DomainError::class );
		$box->seal( 'secret' );
	}
}
