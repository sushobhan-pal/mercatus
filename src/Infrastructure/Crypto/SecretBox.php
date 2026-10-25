<?php
/**
 * Encrypted secrets (SEC-6).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Infrastructure\Crypto;

use Mercatus\Core\Shared\Errors\DomainError;

/**
 * Seals provider keys and similar secrets with `sodium_crypto_secretbox`, under the dedicated
 * `MERCATUS_ENCRYPTION_KEY` constant (32 bytes, base64-encoded), never a key derived from WordPress's salts.
 * Each sealed value starts with its key version, so keys can be rotated later.
 */
final class SecretBox {

	private const VERSION = 'v1';

	/**
	 * The raw key, or null when the site has none.
	 *
	 * @var string|null
	 */
	private ?string $key;

	/**
	 * Creates the box.
	 *
	 * @param string|null $key Base64-encoded 32-byte key; MERCATUS_ENCRYPTION_KEY when omitted.
	 */
	public function __construct( ?string $key = null ) {
		$key     ??= defined( 'MERCATUS_ENCRYPTION_KEY' ) ? self::text( constant( 'MERCATUS_ENCRYPTION_KEY' ) ) : null;
		$raw       = null === $key ? false : base64_decode( $key, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- the key is base64 by definition.
		$this->key = is_string( $raw ) && SODIUM_CRYPTO_SECRETBOX_KEYBYTES === strlen( $raw ) ? $raw : null;
	}

	/**
	 * A configured value as text; wp-config.php may define the constant as anything.
	 *
	 * @param mixed $value Value.
	 */
	private static function text( mixed $value ): ?string {
		return is_string( $value ) ? $value : null;
	}

	/**
	 * Whether the site has a usable key.
	 */
	public function available(): bool {
		return null !== $this->key;
	}

	/**
	 * A secret, sealed.
	 *
	 * @param string $secret Plain secret.
	 * @throws DomainError When the site has no key (mercatus_encryption_key_missing).
	 */
	public function seal( string $secret ): string {
		if ( null === $this->key ) {
			throw DomainError::of( 'mercatus_encryption_key_missing' );
		}
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

		return self::VERSION . ':' . base64_encode( $nonce . sodium_crypto_secretbox( $secret, $nonce, $this->key ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary ciphertext stored as text.
	}

	/**
	 * A sealed secret, opened; null when it can't be, for example after the key changed.
	 *
	 * @param string $sealed Sealed secret.
	 */
	public function open( string $sealed ): ?string {
		if ( null === $this->key || ! str_starts_with( $sealed, self::VERSION . ':' ) ) {
			return null;
		}
		$data = base64_decode( substr( $sealed, strlen( self::VERSION ) + 1 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- see seal().
		if ( ! is_string( $data ) || strlen( $data ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}
		$plain = sodium_crypto_secretbox_open( substr( $data, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $data, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), $this->key );

		return is_string( $plain ) ? $plain : null;
	}
}
