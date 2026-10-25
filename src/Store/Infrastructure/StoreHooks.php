<?php
/**
 * core.store's WordPress hooks (SEC-7).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Store\Infrastructure;

use Mercatus\Core\Contracts\Module\ModuleHooks;
use Mercatus\Core\Vendor\Infrastructure\VendorStore;
use WP_User;

/**
 * The store page and store list (STO-10): rewrite rules, the template inside the theme, the blocks, and the
 * store list page created on the first wp-admin load. The store's contact details and address join WordPress's
 * Export and Erase Personal Data tools: an open store keeps them when erased, because buyers and orders rely on
 * them; a closed store's are cleared.
 */
final class StoreHooks implements ModuleHooks {

	public const PRIVACY_ID = 'mercatus-store';

	/**
	 * Creates the hooks.
	 *
	 * @param Stores      $stores  Store commands.
	 * @param VendorStore $vendors Vendors, to find a user's.
	 * @param StorePage   $page    The store page and store list page.
	 * @param StoreBlocks $blocks  The storefront's blocks.
	 * @param StoreUrls   $urls    Store page addresses.
	 */
	public function __construct( private Stores $stores, private VendorStore $vendors, private StorePage $page, private StoreBlocks $blocks, private StoreUrls $urls ) {}

	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'erasers' ) );
		add_action( 'init', array( $this->page, 'rewrites' ) );
		add_action( 'init', array( $this->blocks, 'register' ) );
		add_filter( 'query_vars', array( $this->page, 'queryVars' ) );
		add_action( 'parse_query', array( $this->page, 'parseQuery' ) );
		add_filter( 'posts_pre_query', array( $this->page, 'skipPosts' ), 10, 2 );
		add_filter( 'pre_handle_404', array( $this->page, 'handle404' ), 10, 2 );
		add_action( 'template_redirect', array( $this->page, 'redirect' ) );
		add_filter( 'template_include', array( $this->page, 'template' ), 99 );
		add_filter( 'document_title_parts', array( $this->page, 'title' ) );
		add_filter( 'body_class', array( $this->page, 'bodyClass' ) );
		add_action( 'admin_init', array( $this, 'adminInit' ) );
	}

	/**
	 * Creates the store list page once, and registers the store page's base for translation (STO-16).
	 */
	public function adminInit(): void {
		$this->page->ensureListPage();
		$this->urls->registerStrings();
	}

	/**
	 * Adds the exporter.
	 *
	 * @param array<string, array<string, mixed>> $exporters Exporters by ID.
	 * @return array<string, array<string, mixed>>
	 */
	public function exporters( array $exporters ): array {
		$exporters[ self::PRIVACY_ID ] = array(
			'exporter_friendly_name' => __( 'Marketplace store', 'mercatus' ),
			'callback'               => array( $this, 'export' ),
		);

		return $exporters;
	}

	/**
	 * Adds the eraser.
	 *
	 * @param array<string, array<string, mixed>> $erasers Erasers by ID.
	 * @return array<string, array<string, mixed>>
	 */
	public function erasers( array $erasers ): array {
		$erasers[ self::PRIVACY_ID ] = array(
			'eraser_friendly_name' => __( 'Marketplace store', 'mercatus' ),
			'callback'             => array( $this, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * The store's export for an email address.
	 *
	 * @param string $email Email address.
	 * @return array{data: list<array<string, mixed>>, done: bool}
	 */
	public function export( string $email ): array {
		$vendor_id = $this->vendorFor( $email );
		$store     = null === $vendor_id ? null : $this->stores->export( $vendor_id );
		$items     = array();
		if ( null !== $store ) {
			$fields = array(
				__( 'Store name', 'mercatus' )    => $store['name'],
				__( 'Store address', 'mercatus' ) => implode( ', ', array_filter( (array) $store['address'] ) ),
				__( 'Contact email', 'mercatus' ) => $store['contact_email'],
				__( 'Contact phone', 'mercatus' ) => $store['contact_phone'],
			);
			$data   = array();
			foreach ( $fields as $name => $value ) {
				if ( is_string( $value ) && '' !== $value ) {
					$data[] = array(
						'name'  => $name,
						'value' => $value,
					);
				}
			}
			$items[] = array(
				'group_id'          => self::PRIVACY_ID,
				'group_label'       => __( 'Marketplace store', 'mercatus' ),
				'group_description' => __( 'Your store on this marketplace.', 'mercatus' ),
				'item_id'           => 'store-' . (int) $store['id'],
				'data'              => $data,
			);
		}

		return array(
			'data' => $items,
			'done' => true,
		);
	}

	/**
	 * Erases a closed store's contact details for an email address; an open store keeps them.
	 *
	 * @param string $email Email address.
	 * @return array{items_removed: bool, items_retained: bool, messages: list<string>, done: bool}
	 */
	public function erase( string $email ): array {
		$vendor_id = $this->vendorFor( $email );
		$erased    = null === $vendor_id ? null : $this->stores->erasePersonalData( $vendor_id );

		return array(
			'items_removed'  => true === $erased,
			'items_retained' => false === $erased,
			'messages'       => false === $erased ? array( __( 'The store’s contact details and address were kept, because the store is open and its buyers and orders rely on them.', 'mercatus' ) ) : array(),
			'done'           => true,
		);
	}

	/**
	 * The vendor an email address belongs to.
	 *
	 * @param string $email Email address.
	 */
	private function vendorFor( string $email ): ?int {
		$user = get_user_by( 'email', $email );

		return $user instanceof WP_User ? $this->vendors->byUserId( $user->ID )?->id : null;
	}
}
