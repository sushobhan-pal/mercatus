<?php
/**
 * The storefront's blocks (ARCH-9, STO-10).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Store\Infrastructure;

use Mercatus\Core\Shared\Errors\DomainError;
use Mercatus\Core\Shared\Http\Cursor;
use Mercatus\Core\Shared\Http\ListDefinition;
use Mercatus\Core\Store\Application\StoreDirectory;

/**
 * Server-rendered and escaped on output (SEC-7): the store page's header and about blocks, which read the store
 * the page found, and the Stores block, which lists listed stores with a name search and a next page.
 * `core.catalog` and `core.reviews` add their blocks to the store template later.
 */
final class StoreBlocks {

	public const EDITOR_SCRIPT = 'mercatus-blocks';

	private const PER_PAGE = 24;

	/**
	 * Creates the blocks.
	 *
	 * @param StorePage      $page   The store this request shows.
	 * @param StoreDirectory $stores Stores.
	 */
	public function __construct( private StorePage $page, private StoreDirectory $stores ) {}

	/**
	 * On `init`: the blocks and their editor script.
	 */
	public function register(): void {
		$root  = dirname( __DIR__, 3 );
		$asset = is_readable( $root . '/build/blocks.asset.php' ) ? require $root . '/build/blocks.asset.php' : null;
		if ( is_array( $asset ) ) {
			wp_register_script( self::EDITOR_SCRIPT, plugins_url( 'build', $root . '/mercatus.php' ) . '/blocks.js', is_array( $asset['dependencies'] ?? null ) ? $asset['dependencies'] : array(), is_string( $asset['version'] ?? null ) ? $asset['version'] : null, true );
			wp_set_script_translations( self::EDITOR_SCRIPT, 'mercatus' );
		}
		$common = array(
			'api_version'           => '3',
			'category'              => 'widgets',
			'icon'                  => 'store',
			'supports'              => array( 'html' => false ),
			'editor_script_handles' => array( self::EDITOR_SCRIPT ),
		);
		register_block_type(
			'mercatus/store-header',
			$common + array(
				'title'           => __( 'Store header', 'mercatus' ),
				'description'     => __( 'The store’s banner, logo, name, tagline and city. Shows on store pages.', 'mercatus' ),
				'render_callback' => array( $this, 'header' ),
			)
		);
		register_block_type(
			'mercatus/store-about',
			$common + array(
				'title'           => __( 'Store details', 'mercatus' ),
				'description'     => __( 'The store’s description, contact details and policies. Shows on store pages.', 'mercatus' ),
				'render_callback' => array( $this, 'about' ),
			)
		);
		register_block_type(
			'mercatus/stores',
			$common + array(
				'title'           => __( 'Stores', 'mercatus' ),
				'description'     => __( 'The marketplace’s open stores, with a search.', 'mercatus' ),
				'render_callback' => array( $this, 'list' ),
			)
		);
	}

	/**
	 * The store header block.
	 */
	public function header(): string {
		$store = $this->page->current();
		if ( null === $store ) {
			return '';
		}
		$images = '';
		foreach ( array(
			'banner_id' => 'full',
			'logo_id'   => 'thumbnail',
		) as $field => $size ) {
			if ( is_int( $store[ $field ] ) ) {
				$images .= wp_get_attachment_image( $store[ $field ], $size, false, array( 'class' => 'mercatus-store-header__' . substr( $field, 0, -3 ) ) );
			}
		}
		$where = self::place( (array) $store['location'] );

		return sprintf(
			'<div %1$s>%2$s<h1 class="mercatus-store-header__name">%3$s</h1>%4$s%5$s</div>',
			get_block_wrapper_attributes( array( 'class' => 'mercatus-store-header' ) ),
			$images,
			esc_html( (string) $store['name'] ),
			'' === $store['tagline'] ? '' : '<p class="mercatus-store-header__tagline">' . esc_html( (string) $store['tagline'] ) . '</p>',
			'' === $where ? '' : '<p class="mercatus-store-header__location">' . esc_html( $where ) . '</p>'
		);
	}

	/**
	 * The store details block: the description, contact details and policies.
	 */
	public function about(): string {
		$store = $this->page->current();
		if ( null === $store ) {
			return '';
		}
		$html    = '' === $store['description'] ? '' : '<div class="mercatus-store-about__description">' . wp_kses( (string) $store['description'], Stores::RICH_TEXT ) . '</div>';
		$contact = (array) $store['contact'];
		if ( array() !== $contact ) {
			$html .= '<h2>' . esc_html__( 'Contact', 'mercatus' ) . '</h2><ul class="mercatus-store-about__contact">';
			if ( is_string( $contact['email'] ?? null ) ) {
				$html .= '<li><a href="' . esc_url( 'mailto:' . $contact['email'] ) . '">' . esc_html( $contact['email'] ) . '</a></li>';
			}
			if ( is_string( $contact['phone'] ?? null ) ) {
				$html .= '<li><a href="' . esc_url( 'tel:' . preg_replace( '/[^0-9+]/', '', $contact['phone'] ) ) . '">' . esc_html( $contact['phone'] ) . '</a></li>';
			}
			$html .= '</ul>';
		}
		$titles = array(
			'shipping' => __( 'Shipping', 'mercatus' ),
			'returns'  => __( 'Returns', 'mercatus' ),
			'privacy'  => __( 'Privacy', 'mercatus' ),
		);
		foreach ( (array) $store['policies'] as $policy => $text ) {
			if ( is_string( $text ) && '' !== $text && isset( $titles[ $policy ] ) ) {
				$html .= '<h2>' . esc_html( $titles[ $policy ] ) . '</h2>' . wpautop( esc_html( $text ) );
			}
		}

		return '<div ' . get_block_wrapper_attributes( array( 'class' => 'mercatus-store-about' ) ) . '>' . $html . '</div>';
	}

	/**
	 * The Stores block: listed stores by name, a name search and a next page, from the query string.
	 */
	public function list(): string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- a public search; nothing changes.
		$search = isset( $_GET['store_search'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['store_search'] ) ) : '';
		$cursor = isset( $_GET['store_cursor'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['store_cursor'] ) ) : '';
		// phpcs:enable
		$listing = new ListDefinition( array( 'name' => 'string' ), array(), self::PER_PAGE, self::PER_PAGE );
		try {
			$request = $listing->parse( '' === $cursor ? array() : array( 'cursor' => $cursor ) );
		} catch ( DomainError ) {
			$request = $listing->parse( array() );
		}
		$rows = $this->stores->listed( $request, mb_substr( $search, 0, 50 ) );
		$more = count( $rows ) > self::PER_PAGE;
		$rows = array_slice( $rows, 0, self::PER_PAGE );

		$html = '<form class="mercatus-stores__search" role="search" method="get"><label><span class="screen-reader-text">' . esc_html__( 'Search stores', 'mercatus' ) . '</span><input type="search" name="store_search" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Search stores', 'mercatus' ) . '"></label> <button type="submit">' . esc_html__( 'Search', 'mercatus' ) . '</button></form>';
		if ( array() === $rows ) {
			$html .= '<p class="mercatus-stores__empty">' . esc_html( '' === $search ? __( 'No stores are open yet.', 'mercatus' ) : __( 'No store matches your search.', 'mercatus' ) ) . '</p>';
		} else {
			$html .= '<ul class="mercatus-stores__list">';
			foreach ( $rows as $row ) {
				$store = $row['store'];
				$where = self::place( (array) $store['location'] );
				$html .= '<li class="mercatus-stores__item"><a href="' . esc_url( (string) $store['url'] ) . '">' . esc_html( (string) $store['name'] ) . '</a>'
					. ( '' === $store['tagline'] ? '' : ' <span class="mercatus-stores__tagline">' . esc_html( (string) $store['tagline'] ) . '</span>' )
					. ( '' === $where ? '' : ' <span class="mercatus-stores__location">' . esc_html( $where ) . '</span>' ) . '</li>';
			}
			$html .= '</ul>';
		}
		$last = end( $rows );
		if ( $more && false !== $last ) {
			$next  = Cursor::encode( 'name', 'asc', $last['name'], $last['id'] );
			$html .= '<p class="mercatus-stores__more"><a href="' . esc_url(
				add_query_arg(
					array_filter(
						array(
							'store_cursor' => $next,
							'store_search' => $search,
						)
					)
				)
			) . '">' . esc_html__( 'More stores', 'mercatus' ) . '</a></p>';
		}

		return '<div ' . get_block_wrapper_attributes( array( 'class' => 'mercatus-stores' ) ) . '>' . $html . '</div>';
	}

	/**
	 * A store's city, state and country, with the country's name.
	 *
	 * @param array<string, mixed> $location City, state and country code.
	 */
	private static function place( array $location ): string {
		$code      = (string) ( $location['country'] ?? '' );
		$countries = function_exists( 'WC' ) ? WC()->countries->get_countries() : array();
		$country   = '' === $code ? '' : (string) ( $countries[ $code ] ?? $code );

		return implode( ', ', array_filter( array( (string) ( $location['city'] ?? '' ), (string) ( $location['state'] ?? '' ), html_entity_decode( $country ) ) ) );
	}
}
