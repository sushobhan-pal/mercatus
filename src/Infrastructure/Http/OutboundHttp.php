<?php
/**
 * Outbound HTTP through an allow-list (SEC-7, TM-31, STO-8).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Infrastructure\Http;

use Closure;
use Mercatus\Core\Shared\Errors\DomainError;

/**
 * The one way Mercatus code calls another host; an architecture test refuses any other. Only HTTPS to a host a
 * module allowed, on the default port, whose every address is public: private, loopback, link-local and
 * reserved addresses are refused after DNS resolution, so a hostname can't be pointed at the server's own
 * network. Redirects aren't followed, and requests time out quickly.
 */
final class OutboundHttp {

	private const TIMEOUT = 5;

	/**
	 * Hosts modules allowed, lower case.
	 *
	 * @var array<string, true>
	 */
	private array $hosts = array();

	/**
	 * Resolves a host to its addresses.
	 *
	 * @var Closure(string): list<string>
	 */
	private Closure $resolve;

	/**
	 * Creates the client.
	 *
	 * @param (Closure(string): list<string>)|null $resolve Resolves a host to its IP addresses; DNS when omitted.
	 */
	public function __construct( ?Closure $resolve = null ) {
		$this->resolve = $resolve ?? static function ( string $host ): array {
			$addresses = gethostbynamel( $host );

			return false === $addresses ? array() : $addresses;
		};
	}

	/**
	 * Allows a host. Modules call this for the providers they talk to.
	 *
	 * @param string $host Host name, for example nominatim.openstreetmap.org.
	 */
	public function allow( string $host ): void {
		$this->hosts[ strtolower( $host ) ] = true;
	}

	/**
	 * A GET to an allowed URL.
	 *
	 * @param string                $url     URL.
	 * @param array<string, string> $headers Request headers.
	 * @return array{status: int, body: string}|null The response, or null when the request failed.
	 * @throws DomainError When the URL isn't allowed (mercatus_outbound_refused).
	 */
	public function get( string $url, array $headers = array() ): ?array {
		$this->assertAllowed( $url );
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'     => self::TIMEOUT,
				'redirection' => 0,
				'headers'     => $headers + array( 'User-Agent' => 'Mercatus (' . home_url( '/' ) . ')' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return null;
		}

		return array(
			'status' => (int) wp_remote_retrieve_response_code( $response ),
			'body'   => wp_remote_retrieve_body( $response ),
		);
	}

	/**
	 * Refuses a URL that isn't HTTPS to an allowed host on the default port, or whose host resolves to an
	 * address that isn't public.
	 *
	 * @param string $url URL.
	 * @throws DomainError When it's refused (mercatus_outbound_refused).
	 */
	public function assertAllowed( string $url ): void {
		$parts = wp_parse_url( $url );
		$host  = is_array( $parts ) && is_string( $parts['host'] ?? null ) ? strtolower( $parts['host'] ) : '';
		if ( ! is_array( $parts ) || 'https' !== ( $parts['scheme'] ?? '' ) || isset( $parts['port'] ) || isset( $parts['user'] ) || ! isset( $this->hosts[ $host ] ) ) {
			throw DomainError::of( 'mercatus_outbound_refused', array( 'host' => $host ) );
		}
		$addresses = ( $this->resolve )( $host );
		if ( array() === $addresses ) {
			throw DomainError::of( 'mercatus_outbound_refused', array( 'host' => $host ) );
		}
		foreach ( $addresses as $address ) {
			if ( false === filter_var( $address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				throw DomainError::of( 'mercatus_outbound_refused', array( 'host' => $host ) );
			}
		}
	}
}
