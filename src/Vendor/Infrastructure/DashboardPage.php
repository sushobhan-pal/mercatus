<?php
/**
 * The marketplace dashboard's page (ARCH-13, VEN-10, VEN-15).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Vendor\Infrastructure;

use Mercatus\Core\Contracts\Settings\SettingsReader;
use Mercatus\Core\Contracts\Settings\SettingsWriter;
use Mercatus\Core\Infrastructure\I18n\Multilingual;
use Mercatus\Core\Vendor\Application\VendorDirectory;
use Mercatus\Core\Vendor\Application\VendorSettings;

/**
 * A WordPress page, recorded in `core.vendor.dashboard_page`, on which Mercatus renders its own minimal
 * document instead of the theme: the React app's scripts and their WordPress dependencies, nothing that other
 * plugins enqueue (VEN-10). The page sends a Content-Security-Policy that allows scripts from the site only,
 * inline data through a per-request nonce, no `unsafe-eval`, and framing by the site only (TM-28). Any
 * translation of the page is the dashboard too (PLAT-11).
 */
final class DashboardPage {

	/**
	 * The app's script and style handle.
	 */
	public const HANDLE = 'mercatus-dashboard';

	/**
	 * This request's script nonce.
	 *
	 * @var string|null
	 */
	private ?string $nonce = null;

	/**
	 * Whether this request shows the dashboard.
	 *
	 * @var bool
	 */
	private bool $serving = false;

	/**
	 * Creates the page.
	 *
	 * @param SettingsReader  $settings  Settings.
	 * @param SettingsWriter  $writer    Records the page Mercatus creates.
	 * @param Multilingual    $languages Translations of the page.
	 * @param VendorDirectory $vendors   The registration form, for visitors and customers.
	 */
	public function __construct( private SettingsReader $settings, private SettingsWriter $writer, private Multilingual $languages, private VendorDirectory $vendors ) {}

	/**
	 * The dashboard page's ID; 0 before Mercatus created it.
	 */
	public function id(): int {
		return $this->settings->int( VendorSettings::DASHBOARD_PAGE );
	}

	/**
	 * Creates the page the first time an administrator loads wp-admin after activation, because the module's
	 * settings only exist once its migrations ran. A page the administrator chose, or later deleted, is left
	 * alone.
	 */
	public function ensure(): void {
		if ( 0 !== $this->id() || ! current_user_can( 'publish_pages' ) ) {
			return;
		}
		$page_id = wp_insert_post(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'post_title'     => __( 'Marketplace dashboard', 'mercatus' ),
				'post_name'      => 'dashboard',
				'post_content'   => '',
				'comment_status' => 'closed',
			),
			true
		);
		if ( ! is_wp_error( $page_id ) ) {
			$this->writer->set( VendorSettings::DASHBOARD_PAGE, $page_id );
		}
	}

	/**
	 * Whether a page is the dashboard or one of its translations.
	 *
	 * @param int $post_id Page.
	 */
	public function isDashboard( int $post_id ): bool {
		return $this->languages->isTranslationOf( $post_id, $this->id() );
	}

	/**
	 * The dashboard's address in the current language; the home page before the page exists.
	 */
	public function url(): string {
		$link = $this->id() > 0 ? get_permalink( $this->languages->inCurrentLanguage( $this->id() ) ) : false;

		return false === $link ? home_url( '/' ) : $link;
	}

	/**
	 * On `template_redirect`: when the dashboard is requested, sends its headers. Page caches must not keep it,
	 * because it carries a nonce.
	 */
	public function redirect(): void {
		if ( ! is_page() || ! $this->isDashboard( (int) get_queried_object_id() ) ) {
			return;
		}
		$this->serving = true;
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- the page-cache plugins' shared convention.
		}
		nocache_headers();
		if ( headers_sent() ) {
			return;
		}
		foreach ( $this->headers() as $name => $value ) {
			header( $name . ': ' . $value );
		}
	}

	/**
	 * On `template_include`: the dashboard's own document instead of the theme's template.
	 *
	 * @param string $template The template WordPress chose.
	 */
	public function template( string $template ): string {
		if ( ! $this->serving ) {
			return $template;
		}
		$this->prepare();

		return self::file();
	}

	/**
	 * The dashboard document, as the template prints it.
	 */
	public function document(): string {
		$this->prepare();
		ob_start();
		include self::file();

		return (string) ob_get_clean();
	}

	/**
	 * The headers the dashboard sends (VEN-10).
	 *
	 * @return array<string, string>
	 */
	public function headers(): array {
		$own = implode( ' ', array_unique( array( "'self'", ...self::origins() ) ) );

		return array(
			'Content-Security-Policy' => implode(
				'; ',
				array(
					"default-src 'self'",
					"script-src {$own} 'nonce-{$this->nonce()}'",
					"style-src {$own} 'unsafe-inline'",
					"img-src {$own} data: https:",
					"font-src {$own} data:",
					"connect-src {$own}",
					"frame-ancestors 'self'",
					"base-uri 'self'",
					"form-action 'self'",
					"object-src 'none'",
				)
			),
			'Referrer-Policy'         => 'same-origin',
			'X-Content-Type-Options'  => 'nosniff',
		);
	}

	/**
	 * This request's script nonce.
	 */
	public function nonce(): string {
		$this->nonce ??= base64_encode( random_bytes( 18 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- a CSP nonce is base64 by definition.

		return $this->nonce;
	}

	/**
	 * Adds this request's nonce to the scripts the dashboard prints, on `wp_script_attributes` and
	 * `wp_inline_script_attributes`.
	 *
	 * @param array<string, mixed> $attributes Script attributes.
	 * @return array<string, mixed>
	 */
	public function scriptAttributes( array $attributes ): array {
		return $attributes + array( 'nonce' => $this->nonce() );
	}

	/**
	 * Registers the app's script and style, with the data it starts from.
	 */
	private function prepare(): void {
		$root  = dirname( __DIR__, 3 );
		$asset = is_readable( $root . '/build/index.asset.php' ) ? require $root . '/build/index.asset.php' : array();
		$asset = is_array( $asset ) ? $asset : array();
		$deps  = is_array( $asset['dependencies'] ?? null ) ? $asset['dependencies'] : array();
		$ver   = is_string( $asset['version'] ?? null ) ? $asset['version'] : null;

		$build = plugins_url( 'build', $root . '/mercatus.php' );

		wp_register_script( self::HANDLE, $build . '/index.js', $deps, $ver, array( 'in_footer' => true ) );
		wp_set_script_translations( self::HANDLE, 'mercatus' );
		wp_add_inline_script( self::HANDLE, 'window.mercatusDashboard = ' . wp_json_encode( $this->bootstrap() ) . ';', 'before' );
		wp_register_style( self::HANDLE, $build . '/index.css', array( 'wp-components' ), $ver );
		wp_style_add_data( self::HANDLE, 'rtl', 'replace' );

		add_filter( 'wp_script_attributes', array( $this, 'scriptAttributes' ) );
		add_filter( 'wp_inline_script_attributes', array( $this, 'scriptAttributes' ) );
	}

	/**
	 * What the app needs before its first API call: whether someone is signed in, where to sign in and out, and
	 * the registration form, so visitors can apply without an account (ARCH-9).
	 *
	 * @return array<string, mixed>
	 */
	private function bootstrap(): array {
		return array(
			'signedIn'     => is_user_logged_in(),
			'loginUrl'     => wp_login_url( $this->url() ),
			'logoutUrl'    => wp_logout_url( $this->url() ),
			'homeUrl'      => home_url( '/' ),
			'siteName'     => get_bloginfo( 'name' ),
			'registration' => $this->vendors->registrationForm(),
		);
	}

	/**
	 * The site's other origins, when WordPress, its scripts or its REST API live on another host than the pages.
	 *
	 * @return list<string>
	 */
	private static function origins(): array {
		$origins = array();
		foreach ( array( home_url( '/' ), site_url( '/' ), includes_url(), plugins_url(), rest_url() ) as $url ) {
			$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
			$host   = wp_parse_url( $url, PHP_URL_HOST );
			$port   = wp_parse_url( $url, PHP_URL_PORT );
			if ( is_string( $scheme ) && is_string( $host ) ) {
				$origins[] = $scheme . '://' . $host . ( is_int( $port ) ? ':' . $port : '' );
			}
		}

		return array_values( array_unique( $origins ) );
	}

	/**
	 * The dashboard's template.
	 */
	private static function file(): string {
		return dirname( __DIR__, 3 ) . '/templates/dashboard.php';
	}
}
