<?php
/**
 * The public store page and the store list page (ARCH-9, STO-10).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Store\Infrastructure;

use Mercatus\Core\Contracts\Settings\SettingsReader;
use Mercatus\Core\Contracts\Settings\SettingsWriter;
use Mercatus\Core\Shared\Errors\DomainError;
use Mercatus\Core\Store\Application\StoreDirectory;
use Mercatus\Core\Store\Application\StoreSettings;
use WP_Query;

/**
 * `/{base}/{slug}/` renders inside the active theme. A block theme gets the store template through WordPress's
 * template canvas: the theme's own `mercatus-store` template when it has one, otherwise Mercatus's. A classic
 * theme gets `mercatus/store.php` from the theme when it has one, otherwise Mercatus's, between its own header
 * and footer. The content comes from server-rendered blocks reading the same serializer as the API (ARCH-9).
 * A store the visitor can't see is a 404, and an owner's preview of their hidden store is never cached or
 * indexed. The store list is a page with the Stores block.
 */
final class StorePage {

	public const QUERY_VAR = 'mercatus_store';

	/**
	 * The option remembering which bases the rewrite rules were flushed for.
	 */
	private const REWRITES = 'mercatus_store_rewrites';

	/**
	 * The template a block theme may provide.
	 */
	public const BLOCK_TEMPLATE = 'mercatus-store';

	/**
	 * The store page's blocks.
	 */
	public const CONTENT = '<!-- wp:mercatus/store-header /--><!-- wp:mercatus/store-about /-->';

	/**
	 * The store this request shows, as the public sees it.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $store = null;

	/**
	 * Creates the page.
	 *
	 * @param StoreDirectory $stores   Stores.
	 * @param StoreUrls      $urls     Store page addresses.
	 * @param SettingsReader $settings Settings.
	 * @param SettingsWriter $writer   Records the store list page Mercatus creates.
	 */
	public function __construct( private StoreDirectory $stores, private StoreUrls $urls, private SettingsReader $settings, private SettingsWriter $writer ) {}

	/**
	 * On `init`: one rewrite rule per base, in every language, flushed once when the bases change.
	 */
	public function rewrites(): void {
		$bases = $this->urls->bases();
		foreach ( $bases as $base ) {
			add_rewrite_rule( '^' . preg_quote( $base, '#' ) . '/([a-z0-9-]+)/?$', 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top' );
		}
		$signature = implode( ',', $bases );
		if ( get_option( self::REWRITES ) !== $signature ) {
			flush_rewrite_rules( false );
			update_option( self::REWRITES, $signature, true );
		}
	}

	/**
	 * On `query_vars`.
	 *
	 * @param list<string> $vars Public query variables.
	 * @return list<string>
	 */
	public function queryVars( array $vars ): array {
		$vars[] = self::QUERY_VAR;

		return $vars;
	}

	/**
	 * On `parse_query`: a store request is neither the blog home nor an archive.
	 *
	 * @param WP_Query $query Query.
	 */
	public function parseQuery( WP_Query $query ): void {
		if ( $query->is_main_query() && '' !== (string) $query->get( self::QUERY_VAR ) ) {
			$query->is_home    = false;
			$query->is_archive = false;
		}
	}

	/**
	 * On `posts_pre_query`: a store request asks for no posts, so WordPress skips the posts query.
	 *
	 * @param array<int, mixed>|null $posts Posts, or null to query.
	 * @param WP_Query               $query Query.
	 * @return array<int, mixed>|null
	 */
	public function skipPosts( ?array $posts, WP_Query $query ): ?array {
		return $query->is_main_query() && '' !== (string) $query->get( self::QUERY_VAR ) ? array() : $posts;
	}

	/**
	 * On `pre_handle_404`: a store request has no posts, which WordPress would call a 404 before the store is
	 * looked up; redirect() decides instead.
	 *
	 * @param bool     $preempt Whether 404 handling is already done.
	 * @param WP_Query $query   Main query.
	 */
	public function handle404( bool $preempt, WP_Query $query ): bool {
		return '' !== (string) $query->get( self::QUERY_VAR ) ? true : $preempt;
	}

	/**
	 * On `template_redirect`: finds the store, or makes the request a 404.
	 */
	public function redirect(): void {
		$this->store = null;
		$slug        = (string) get_query_var( self::QUERY_VAR );
		if ( '' === $slug ) {
			return;
		}
		try {
			$result = $this->stores->public( $slug );
		} catch ( DomainError ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
			return;
		}
		$this->store = $result['store'];
		if ( ! $result['listed'] ) {
			// An owner's or administrator's preview of a store the public can't see.
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- the page-cache plugins' shared convention.
			}
			nocache_headers();
			add_filter( 'wp_robots', 'wp_robots_no_robots' );
		}
	}

	/**
	 * On `template_include`: the store template, inside the theme.
	 *
	 * @param string $template The template WordPress chose.
	 */
	public function template( string $template ): string {
		if ( null === $this->store ) {
			return $template;
		}
		if ( wp_is_block_theme() ) {
			$theme_template = get_block_template( get_stylesheet() . '//' . self::BLOCK_TEMPLATE );
			// The block template canvas renders whatever this global holds, as core's block templates do.
			$GLOBALS['_wp_current_template_content'] = null === $theme_template ? self::blockTemplate() : $theme_template->content; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- core's template canvas reads it.

			return ABSPATH . 'wp-includes/template-canvas.php';
		}
		$theme = locate_template( array( 'mercatus/store.php' ) );

		return '' === $theme ? dirname( __DIR__, 3 ) . '/templates/store.php' : $theme;
	}

	/**
	 * On `document_title_parts`: the store's name.
	 *
	 * @param array<string, string> $parts Title parts.
	 * @return array<string, string>
	 */
	public function title( array $parts ): array {
		if ( null !== $this->store ) {
			$parts['title'] = (string) $this->store['name'];
		}

		return $parts;
	}

	/**
	 * On `body_class`.
	 *
	 * @param list<string> $classes Classes.
	 * @return list<string>
	 */
	public function bodyClass( array $classes ): array {
		if ( null !== $this->store ) {
			$classes[] = 'mercatus-store-page';
		}

		return $classes;
	}

	/**
	 * The store this request shows, for the store blocks.
	 *
	 * @return array<string, mixed>|null
	 */
	public function current(): ?array {
		return $this->store;
	}

	/**
	 * Creates the store list page the first time someone who can publish pages loads wp-admin, as the
	 * dashboard page is (VEN-19). A page the administrator chose, or later deleted, is left alone.
	 */
	public function ensureListPage(): void {
		if ( 0 !== $this->settings->int( StoreSettings::STORE_LIST_PAGE ) || ! current_user_can( 'publish_pages' ) ) {
			return;
		}
		$page_id = wp_insert_post(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'post_title'     => __( 'Stores', 'mercatus' ),
				'post_name'      => 'stores',
				'post_content'   => '<!-- wp:mercatus/stores /-->',
				'comment_status' => 'closed',
			),
			true
		);
		if ( ! is_wp_error( $page_id ) ) {
			$this->writer->set( StoreSettings::STORE_LIST_PAGE, $page_id );
		}
	}

	/**
	 * The store template for block themes: the theme's header and footer around the store blocks.
	 */
	private static function blockTemplate(): string {
		return '<!-- wp:template-part {"slug":"header","tagName":"header"} /-->'
			. '<!-- wp:group {"tagName":"main","layout":{"type":"constrained"}} --><main class="wp-block-group">' . self::CONTENT . '</main><!-- /wp:group -->'
			. '<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->';
	}
}
