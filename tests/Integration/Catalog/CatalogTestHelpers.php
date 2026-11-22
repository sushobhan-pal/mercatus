<?php
/**
 * Helpers for catalog integration tests.
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Integration\Catalog;

use Mercatus\Core\Catalog\Domain\Product;
use Mercatus\Core\Catalog\Infrastructure\AvailabilityRepository;
use Mercatus\Core\Catalog\Infrastructure\ProductRepository;
use Mercatus\Core\Tests\Integration\Store\StoreTestHelpers;
use RuntimeException;
use WC_Product;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Vendors with stores, products created through the API, and the projection's rows.
 */
trait CatalogTestHelpers {

	use StoreTestHelpers;

	/**
	 * Calls an endpoint as the current user, with headers.
	 *
	 * @param string                $method  Method.
	 * @param string                $route   Route below the namespace.
	 * @param array<string, mixed>  $params  Query (GET) or body parameters.
	 * @param array<string, string> $headers Headers.
	 */
	private function request( string $method, string $route, array $params = array(), array $headers = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/mercatus/v1' . $route );
		'GET' === $method ? $request->set_query_params( $params ) : $request->set_body_params( $params );
		foreach ( $headers as $name => $value ) {
			$request->set_header( $name, $value );
		}

		return rest_do_request( $request );
	}

	/**
	 * Creates a product as the current vendor.
	 *
	 * @param array<string, mixed> $fields Fields; a simple product named Old map at 20 by default.
	 * @return array<string, mixed>
	 */
	private function createProduct( array $fields = array() ): array {
		$fields += Product::VARIABLE === ( $fields['type'] ?? null ) ? array() : self::simple();

		return $this->data( $this->request( 'POST', '/me/products', $fields, array( 'Idempotency-Key' => wp_generate_uuid4() ) ), 201 );
	}

	/**
	 * A simple product's fields.
	 *
	 * @return array<string, mixed>
	 */
	private static function simple(): array {
		return array(
			'type'          => Product::SIMPLE,
			'name'          => 'Old map',
			'regular_price' => '20',
		);
	}

	/**
	 * A variable product's fields: sizes S and M at 10 and 12.
	 *
	 * @return array<string, mixed>
	 */
	private static function variable(): array {
		return array(
			'type'       => Product::VARIABLE,
			'name'       => 'Linen shirt',
			'attributes' => array(
				array(
					'name'   => 'Fit',
					'values' => array( 'S', 'M' ),
				),
			),
			'variations' => array(
				array(
					'attributes'    => array( 'Fit' => 'S' ),
					'regular_price' => '10',
					'sku'           => 'SHIRT-S',
					'stock'         => array(
						'manage_stock' => true,
						'quantity'     => '4',
					),
				),
				array(
					'attributes'    => array( 'Fit' => 'M' ),
					'regular_price' => '12',
					'sku'           => 'SHIRT-M',
				),
			),
		);
	}

	/**
	 * Changes one of the current vendor's products at its current version.
	 *
	 * @param int                  $id      Product ID.
	 * @param array<string, mixed> $changes Changes.
	 * @param int                  $status  Expected status.
	 * @return array<string, mixed>
	 */
	private function changeProduct( int $id, array $changes, int $status = 200 ): array {
		$etag = $this->etag( $this->request( 'GET', '/me/products/' . $id ) );

		return $this->data( $this->request( 'PATCH', '/me/products/' . $id, $changes, array( 'If-Match' => $etag ) ), $status );
	}

	/**
	 * Runs a product action as the current user.
	 *
	 * @param int    $id     Product ID.
	 * @param string $action publish or unpublish.
	 * @param int    $status Expected status.
	 * @return array<string, mixed>
	 */
	private function act( int $id, string $action, int $status = 200 ): array {
		return $this->data( $this->request( 'POST', '/me/products/' . $id . '/' . $action ), $status );
	}

	/**
	 * Uploads an image as the current vendor.
	 *
	 * @param string|null      $bytes  The file; a small JPEG by default.
	 * @param non-empty-string $name   The name it's uploaded with.
	 * @param int              $status Expected status.
	 * @return array<string, mixed>
	 */
	private function uploadImage( ?string $bytes = null, string $name = 'map.jpg', int $status = 201 ): array {
		return $this->data( $this->upload( $bytes ?? self::jpeg(), $name ), $status );
	}

	/**
	 * Sends a file to the upload endpoint as the current user, the way PHP hands an upload to WordPress. The
	 * type the browser claims is always image/jpeg: it's never trusted.
	 *
	 * @param string                $bytes   The file.
	 * @param non-empty-string      $name    The name it's uploaded with.
	 * @param array<string, string> $headers Headers.
	 * @throws RuntimeException When there's no temporary file.
	 */
	private function upload( string $bytes, string $name, array $headers = array() ): WP_REST_Response {
		$path = wp_tempnam( 'mercatus-upload' );
		if ( '' === $path ) {
			throw new RuntimeException( 'No temporary file.' );
		}
		file_put_contents( $path, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a test's upload.
		$request = new WP_REST_Request( 'POST', '/mercatus/v1/me/media' );
		$request->set_file_params(
			array(
				'file' => array(
					'name'     => $name,
					'type'     => 'image/jpeg',
					'tmp_name' => $path,
					'error'    => UPLOAD_ERR_OK,
					'size'     => strlen( $bytes ),
				),
			)
		);
		foreach ( $headers as $header => $value ) {
			$request->set_header( $header, $value );
		}
		$response = rest_do_request( $request );
		wp_delete_file( $path );

		return $response;
	}

	/**
	 * A small JPEG.
	 *
	 * @param int $width  Width.
	 * @param int $height Height.
	 */
	private static function jpeg( int $width = 40, int $height = 30 ): string {
		return self::image( 'imagejpeg', $width, $height );
	}

	/**
	 * A small PNG.
	 */
	private static function png(): string {
		return self::image( 'imagepng', 40, 30 );
	}

	/**
	 * An image made with GD.
	 *
	 * @param callable(\GdImage): bool $save   imagejpeg or imagepng.
	 * @param int                      $width  Width.
	 * @param int                      $height Height.
	 * @throws RuntimeException When GD can't.
	 */
	private static function image( callable $save, int $width, int $height ): string {
		$image = imagecreatetruecolor( max( 1, $width ), max( 1, $height ) );
		if ( false === $image ) {
			throw new RuntimeException( 'GD could not make an image.' );
		}
		imagefill( $image, 0, 0, (int) imagecolorallocate( $image, 200, 120, 40 ) );
		ob_start();
		$save( $image );

		return (string) ob_get_clean();
	}

	/**
	 * An attachment no vendor uploaded, such as one of the site's own.
	 */
	private static function siteAttachment(): int {
		return wp_insert_attachment(
			array(
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'Site image',
				'post_status'    => 'inherit',
			)
		);
	}

	/**
	 * How many attachments exist.
	 */
	private static function attachmentCount(): int {
		return count(
			get_posts(
				array(
					'post_type'   => 'attachment',
					'post_status' => 'any',
					'numberposts' => -1,
					'fields'      => 'ids',
				)
			)
		);
	}

	/**
	 * A WooCommerce product or variation that must exist.
	 *
	 * @param int $id Its ID.
	 */
	private static function woo( int $id ): WC_Product {
		$product = wc_get_product( $id );
		if ( ! $product instanceof WC_Product ) {
			throw new RuntimeException( 'No WooCommerce product.' );
		}

		return $product;
	}

	/**
	 * A refusal's code and the field it names.
	 *
	 * @param WP_REST_Response $response Response.
	 * @param int              $status   Expected status.
	 * @return array{0: string, 1: mixed}
	 */
	private function refusal( WP_REST_Response $response, int $status ): array {
		$data = $this->data( $response, $status );

		return array( (string) $data['code'], ( (array) ( $data['details'] ?? array() ) )['field'] ?? null );
	}

	/**
	 * A product's catalog row.
	 *
	 * @param int $id WooCommerce product ID.
	 */
	private static function catalogProduct( int $id ): Product {
		return self::kernel()->container()->get( ProductRepository::class )->byWooId( $id ) ?? throw new RuntimeException( 'No catalog product.' );
	}

	/**
	 * A product's projection rows, by key.
	 *
	 * @param int $id WooCommerce product ID.
	 * @return array<string, array<string, string|null>>
	 */
	private static function rows( int $id ): array {
		return self::kernel()->container()->get( AvailabilityRepository::class )->rows( self::catalogProduct( $id )->id );
	}

	/**
	 * A product's row for the product as a whole.
	 *
	 * @param int $id WooCommerce product ID.
	 * @return array<string, string|null>
	 */
	private static function productRow( int $id ): array {
		foreach ( self::rows( $id ) as $row ) {
			if ( '0' === $row['variation_id'] ) {
				return $row;
			}
		}

		throw new RuntimeException( 'No product row.' );
	}
}
