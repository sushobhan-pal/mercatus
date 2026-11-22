<?php
/**
 * core.catalog's settings form (P-1, CTL-20).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Catalog\Api;

use Mercatus\Core\Catalog\Application\CatalogSettings;
use Mercatus\Core\Contracts\Settings\SettingsForm;

/**
 * Labels are translated here, in the API layer (ARCH-7).
 */
final class CatalogSettingsForm implements SettingsForm {

	public function title(): string {
		return __( 'Products', 'mercatus' );
	}

	public function fields(): array {
		return array(
			CatalogSettings::REVIEW_NEW_PRODUCTS   => array(
				'label' => __( 'Review new products before they’re published', 'mercatus' ),
				'help'  => __( 'A vendor’s new product waits for a moderator the first time it’s published. Once approved, later changes don’t need review.', 'mercatus' ),
			),
			CatalogSettings::UPLOAD_MAX_MB         => array(
				'label' => __( 'Largest image a vendor may upload (MB)', 'mercatus' ),
				'help'  => __( 'JPEG, PNG, GIF and WebP, plus AVIF where the server can process it. Each image is saved again without its camera and location data.', 'mercatus' ),
			),
			CatalogSettings::VENDOR_MEDIA_QUOTA_MB => array(
				'label' => __( 'Media space per vendor (MB)', 'mercatus' ),
				'help'  => __( 'Counts each image with the sizes WordPress makes of it.', 'mercatus' ),
			),
		);
	}
}
