<?php
/**
 * The geocoder the marketplace chose (STO-14).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Store\Infrastructure\Geocoding;

use Mercatus\Core\Contracts\Location\Geocoder;
use Mercatus\Core\Contracts\Settings\SettingsReader;
use Mercatus\Core\Infrastructure\Http\OutboundHttp;
use Mercatus\Core\Store\Application\StoreSettings;

/**
 * None until the administrator picks one (`core.store.geocoder`).
 */
final class Geocoders {

	/**
	 * Creates the selector.
	 *
	 * @param SettingsReader $settings Settings.
	 * @param OutboundHttp   $http     Outbound HTTP.
	 */
	public function __construct( private SettingsReader $settings, private OutboundHttp $http ) {}

	/**
	 * The chosen geocoder, or null when geocoding is off.
	 */
	public function current(): ?Geocoder {
		return match ( $this->settings->string( StoreSettings::GEOCODER ) ) {
			'nominatim' => new NominatimGeocoder( $this->http ),
			'google'    => new GoogleGeocoder( $this->http, $this->settings->string( StoreSettings::GOOGLE_GEOCODING_KEY ) ),
			default     => null,
		};
	}
}
