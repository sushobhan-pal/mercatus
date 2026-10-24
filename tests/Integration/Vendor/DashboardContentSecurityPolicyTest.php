<?php
/**
 * The dashboard's Content-Security-Policy (TM-28, VEN-10).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Integration\Vendor;

use WP_UnitTestCase;

/**
 * The dashboard page allows scripts from the site only, inline data only with this request's nonce, never
 * `unsafe-eval`, and framing by the site only. Every script it prints comes from the site or carries the nonce,
 * and scripts other plugins enqueue are left out. On any other page, the theme renders as usual.
 */
final class DashboardContentSecurityPolicyTest extends WP_UnitTestCase {

	use DashboardTestPage;

	public function tear_down(): void {
		$this->forgetDashboardPage();
		parent::tear_down();
	}

	public function testThePolicyAllowsOnlyTheSiteAndThisRequestsNonce(): void {
		list( $dashboard ) = $this->dashboardPage();
		$policy            = self::directives( $dashboard->headers()['Content-Security-Policy'] );
		$site              = self::origin( home_url() );

		$this->assertSame( array( "'self'", $site, "'nonce-" . $dashboard->nonce() . "'" ), $policy['script-src'] );
		$this->assertSame( array( "'self'" ), $policy['frame-ancestors'] );
		$this->assertSame( array( "'none'" ), $policy['object-src'] );
		$this->assertSame( array( "'self'" ), $policy['base-uri'] );
		$this->assertSame( array( "'self'", $site ), $policy['connect-src'] );
		foreach ( $policy as $directive => $sources ) {
			$this->assertNotContains( "'unsafe-eval'", $sources, $directive );
			$this->assertNotContains( '*', $sources, $directive );
		}
		$this->assertNotContains( "'unsafe-inline'", $policy['script-src'] );
		$this->assertMatchesRegularExpression( '#^[A-Za-z0-9+/]{24}$#', $dashboard->nonce() );
	}

	public function testEveryScriptComesFromTheSiteOrCarriesTheNonce(): void {
		list( $dashboard, $page_id ) = $this->dashboardPage();
		wp_enqueue_script( 'another-plugin', 'https://cdn.example.com/tracker.js', array(), '1.0', true );
		wp_add_inline_script( 'another-plugin', 'window.tracked = true;' );
		$this->go_to( (string) get_permalink( $page_id ) );

		$document = $dashboard->document();

		$this->assertStringContainsString( '<div id="mercatus-dashboard"></div>', $document );
		$this->assertStringContainsString( 'window.mercatusDashboard = ', $document );
		$this->assertStringNotContainsString( 'cdn.example.com', $document );
		$this->assertStringNotContainsString( 'window.tracked', $document );
		$this->assertGreaterThan( 0, preg_match_all( '#<script\b([^>]*)>#i', $document, $scripts ) );
		foreach ( $scripts[1] as $attributes ) {
			if ( 1 === preg_match( '#\ssrc=["\']([^"\']+)#', $attributes, $src ) ) {
				$this->assertSame( self::origin( home_url() ), self::origin( $src[1] ), $attributes );
				continue;
			}
			$this->assertStringContainsString( 'nonce="' . $dashboard->nonce() . '"', $attributes );
		}
		wp_dequeue_script( 'another-plugin' );
	}

	public function testTheDashboardReplacesTheThemeOnItsPageOnly(): void {
		list( $dashboard, $page_id ) = $this->dashboardPage();
		$other                       = $this->page();

		$this->go_to( (string) get_permalink( $other ) );
		$dashboard->redirect();
		$this->assertSame( '/theme/page.php', $dashboard->template( '/theme/page.php' ) );

		$this->go_to( (string) get_permalink( $page_id ) );
		$dashboard->redirect();
		$this->assertStringEndsWith( '/templates/dashboard.php', $dashboard->template( '/theme/page.php' ) );
	}

	/**
	 * A policy's directives and their sources.
	 *
	 * @param string $policy Header value.
	 * @return array<string, list<string>>
	 */
	private static function directives( string $policy ): array {
		$directives = array();
		foreach ( array_filter( array_map( 'trim', explode( ';', $policy ) ) ) as $directive ) {
			$parts                                        = preg_split( '/\s+/', $directive );
			$parts                                        = false === $parts ? array() : $parts;
			$directives[ (string) array_shift( $parts ) ] = $parts;
		}

		return $directives;
	}

	/**
	 * A URL's origin.
	 *
	 * @param string $url URL.
	 */
	private static function origin( string $url ): string {
		$port = wp_parse_url( $url, PHP_URL_PORT );

		return wp_parse_url( $url, PHP_URL_SCHEME ) . '://' . wp_parse_url( $url, PHP_URL_HOST ) . ( is_int( $port ) ? ':' . $port : '' );
	}
}
