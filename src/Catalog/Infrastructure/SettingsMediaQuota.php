<?php
/**
 * Core's media quota: the administrator's setting (CTL-24).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Catalog\Infrastructure;

use Mercatus\Core\Catalog\Application\CatalogSettings;
use Mercatus\Core\Contracts\Media\MediaQuota;
use Mercatus\Core\Contracts\Settings\SettingsReader;

/**
 * Every vendor gets `core.catalog.vendor_media_quota_mb`; Pro's Memberships can supply a quota per plan
 * through the same contract.
 */
final class SettingsMediaQuota implements MediaQuota {

	/**
	 * Creates the quota.
	 *
	 * @param SettingsReader $settings Settings.
	 */
	public function __construct( private SettingsReader $settings ) {}

	public function bytes( int $vendor_id ): int {
		return $this->settings->int( CatalogSettings::VENDOR_MEDIA_QUOTA_MB ) * 1048576;
	}
}
