<?php
/**
 * Who a request counts against (IDN-9, IDN-10).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Identity\Infrastructure;

use Mercatus\Core\Contracts\Identity\ActorIdentity;
use Mercatus\Core\Contracts\Identity\CurrentActor;
use Mercatus\Core\Contracts\Settings\SettingsReader;
use Mercatus\Core\Identity\Application\IdentitySettings;

/**
 * The API client, the signed-in user, or the visitor's IP address. Rate limits count against it, and
 * idempotency keys are scoped to it. The IP comes from the connection unless the administrator names the
 * header a trusted proxy or CDN sets, since a spoofable header must never be trusted by default.
 */
final class RequestSubject {

	/**
	 * Creates the subject.
	 *
	 * @param CurrentActor   $actors   The current actor.
	 * @param SettingsReader $settings Settings.
	 */
	public function __construct( private CurrentActor $actors, private SettingsReader $settings ) {}

	/**
	 * The current identity.
	 */
	public function identity(): ActorIdentity {
		return $this->actors->identity();
	}

	/**
	 * client, user or visitor.
	 */
	public function kind(): string {
		$identity = $this->identity();
		if ( null !== $identity->apiClientId ) {
			return 'client';
		}

		return $identity->isSignedIn() ? 'user' : 'visitor';
	}

	/**
	 * client:ID, user:ID or ip:ADDRESS.
	 */
	public function key(): string {
		$identity = $this->identity();

		return match ( $this->kind() ) {
			'client' => 'client:' . $identity->apiClientId,
			'user'   => 'user:' . $identity->userId,
			default  => 'ip:' . $this->address(),
		};
	}

	/**
	 * The visitor's IP address.
	 */
	public function address(): string {
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
