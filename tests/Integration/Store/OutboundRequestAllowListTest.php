<?php
/**
 * Outbound requests go only where they're allowed (TM-31, SEC-7, STO-8).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Integration\Store;

use Mercatus\Core\Infrastructure\Http\OutboundHttp;
use Mercatus\Core\Shared\Errors\DomainError;
use WP_UnitTestCase;

/**
 * A host off the list, plain HTTP, another port, credentials in the URL, or an allowed host that resolves to a
 * private, loopback or link-local address is refused before any request; an allowed host on a public address
 * is called once, without following redirects.
 */
final class OutboundRequestAllowListTest extends WP_UnitTestCase {

	/**
	 * Requests WordPress was asked to make.
	 *
	 * @var list<array{string, array<string, mixed>}>
	 */
	private array $requests = array();

	public function set_up(): void {
		parent::set_up();
		add_filter( 'pre_http_request', array( $this, 'intercept' ), 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'intercept' ) );
		parent::tear_down();
	}

	/**
	 * URLs that are refused, and what the allowed host resolves to.
	 *
	 * @return array<string, array{string, list<string>}>
	 */
	public static function refused(): array {
		return array(
			'a host off the list'        => array( 'https://evil.example/search', array( '93.184.216.34' ) ),
			'plain HTTP'                 => array( 'http://geo.example/search', array( '93.184.216.34' ) ),
			'another port'               => array( 'https://geo.example:8443/search', array( '93.184.216.34' ) ),
			'credentials in the URL'     => array( 'https://user:pass@geo.example/search', array( '93.184.216.34' ) ),
			'loopback'                   => array( 'https://geo.example/search', array( '127.0.0.1' ) ),
			'a private network'          => array( 'https://geo.example/search', array( '10.0.0.5' ) ),
			'link-local metadata'        => array( 'https://geo.example/search', array( '169.254.169.254' ) ),
			'one bad address of two'     => array( 'https://geo.example/search', array( '93.184.216.34', '192.168.1.2' ) ),
			'a host that won\'t resolve' => array( 'https://geo.example/search', array() ),
		);
	}

	/**
	 * Refuses a URL before any request.
	 *
	 * @dataProvider refused
	 * @param string       $url       URL.
	 * @param list<string> $addresses What geo.example resolves to.
	 */
	public function testRefusesBeforeAnyRequest( string $url, array $addresses ): void {
		$http = self::client( $addresses );

		try {
			$http->get( $url );
			$this->fail( 'The request should have been refused.' );
		} catch ( DomainError $error ) {
			$this->assertSame( 'mercatus_outbound_refused', $error->errorCode );
		}
		$this->assertSame( array(), $this->requests );
	}

	public function testCallsAnAllowedPublicHostWithoutFollowingRedirects(): void {
		$response = self::client( array( '93.184.216.34' ) )->get( 'https://geo.example/search?q=Leeds' );

		$this->assertSame(
			array(
				'status' => 200,
				'body'   => '[]',
			),
			$response
		);
		$this->assertCount( 1, $this->requests );
		$this->assertSame( 0, $this->requests[0][1]['redirection'] );
		$this->assertLessThanOrEqual( 5, $this->requests[0][1]['timeout'] );
	}

	/**
	 * Answers every request with an empty JSON list instead of calling out.
	 *
	 * @param false|array<string, mixed> $preempt Whether to short-circuit.
	 * @param array<string, mixed>       $args    Request arguments.
	 * @param string                     $url     URL.
	 * @return array<string, mixed>
	 */
	public function intercept( false|array $preempt, array $args, string $url ): array {
		$this->requests[] = array( $url, $args );

		return array(
			'headers'  => array(),
			'body'     => '[]',
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * A client that allows geo.example, resolving it to the given addresses.
	 *
	 * @param list<string> $addresses Addresses.
	 */
	private static function client( array $addresses ): OutboundHttp {
		$http = new OutboundHttp( static fn( string $host ): array => '' === $host ? array() : $addresses );
		$http->allow( 'geo.example' );

		return $http;
	}
}
