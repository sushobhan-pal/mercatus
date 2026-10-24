<?php
/**
 * The visitor's IP address (IDN-10, IDN-11).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Identity\Infrastructure;

use Mercatus\Core\Contracts\Settings\SettingsReader;
use Mercatus\Core\Identity\Application\IdentitySettings;

/**
 * The connection's address, unless the administrator names the header a trusted proxy or CDN sets: a
 * spoofable header must never be trusted by default.
 */
final class ClientAddress {

	/**
	 * Creates the resolver.
	 *
	 * @param SettingsReader $settings Settings.
	 */
	public function __construct( private SettingsReader $settings ) {}

	/**
	 * The current request's address; 0.0.0.0 when there's none.
	 */
	public function current(): string {
		$header    = $this->settings->string( IdentitySettings::TRUSTED_PROXY_HEADER );
		$candidate = '';
		if ( '' !== $header ) {
			$name      = 'HTTP_' . strtoupper( str_replace( '-', '_', $header ) );
			$values    = isset( $_SERVER[ $name ] ) ? explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $name ] ) ) ) : array();
			$candidate = trim( $values[0] ?? '' );
		}
		if ( false === filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
			$candidate = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		}

		return false === filter_var( $candidate, FILTER_VALIDATE_IP ) ? '0.0.0.0' : $candidate;
	}
}
