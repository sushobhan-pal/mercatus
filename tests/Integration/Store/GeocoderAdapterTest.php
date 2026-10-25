<?php
/**
 * The geocoders and geocoding after an address change (CAT-6, STO-14).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Integration\Store;

use Mercatus\Core\Contracts\Location\Coordinates;
use Mercatus\Core\Contracts\Location\Geocoder;
use Mercatus\Core\Infrastructure\Http\OutboundHttp;
use Mercatus\Core\Store\Infrastructure\Geocoding\GoogleGeocoder;
use Mercatus\Core\Store\Infrastructure\Geocoding\NominatimGeocoder;
use Mercatus\Core\Store\Infrastructure\Stores;
use WP_UnitTestCase;

/**
 * Each adapter turns its provider's answer into coordinates and gives null for no result or a failure. After an
 * address changes, the location gets the coordinates and their geohash cell, unless the address changed again
 * meanwhile; a failure leaves them empty without touching the save.
 */
final class GeocoderAdapterTest extends WP_UnitTestCase {

	use StoreTestHelpers;

	/**
	 * The next answer, by status and body.
	 *
	 * @var array{int, string}
	 */
	private array $answer = array( 200, '[]' );

	/**
	 * URLs asked.
	 *
	 * @var list<string>
	 */
	private array $urls = array();

	public function set_up(): void {
		parent::set_up();
		add_filter( 'pre_http_request', array( $this, 'intercept' ), 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'intercept' ) );
		parent::tear_down();
	}

	public function testNominatimReadsTheFirstResult(): void {
		$this->answer = array( 200, '[{"lat":"53.7997","lon":"-1.5492"}]' );

		$point = ( new NominatimGeocoder( self::http() ) )->geocode( self::address() );

		$this->assertEquals( new Coordinates( 53.7997, -1.5492 ), $point );
		$this->assertStringStartsWith( 'https://nominatim.openstreetmap.org/search?', $this->urls[0] );
		$this->assertStringContainsString( 'city=Leeds', $this->urls[0] );
		$this->assertStringContainsString( 'country=GB', $this->urls[0] );
	}

	public function testGoogleReadsTheFirstResultAndNeedsAKey(): void {
		$this->answer = array( 200, '{"status":"OK","results":[{"geometry":{"location":{"lat":53.7997,"lng":-1.5492}}}]}' );

		$this->assertEquals( new Coordinates( 53.7997, -1.5492 ), ( new GoogleGeocoder( self::http(), 'test-key' ) )->geocode( self::address() ) );
		$this->assertStringContainsString( 'components=country%3AGB', $this->urls[0] );
		$this->assertNull( ( new GoogleGeocoder( self::http(), '' ) )->geocode( self::address() ) );
		$this->assertCount( 1, $this->urls, 'No key, no request.' );
	}

	public function testNoResultOrAFailureIsNull(): void {
		$this->answer = array( 200, '[]' );
		$this->assertNull( ( new NominatimGeocoder( self::http() ) )->geocode( self::address() ) );
		$this->answer = array( 503, 'busy' );
		$this->assertNull( ( new NominatimGeocoder( self::http() ) )->geocode( self::address() ) );
		$this->answer = array( 200, '{"status":"ZERO_RESULTS","results":[]}' );
		$this->assertNull( ( new GoogleGeocoder( self::http(), 'test-key' ) )->geocode( self::address() ) );
	}

	public function testALocationGetsItsCoordinatesAfterTheAddressChanges(): void {
		$vendor = $this->vendorWithStore();
		$this->data( $this->call( 'PATCH', '/me/store', array( 'address' => self::address() ), $this->etag( $this->call( 'GET', '/me/store' ) ) ), 200 );
		$stores = self::kernel()->container()->get( Stores::class );

		$this->assertFalse( $stores->geocode( $this->locationId( $vendor['vendor_id'] ), self::fixed( null ) ), 'A failure records nothing.' );
		$this->assertTrue( $stores->geocode( $this->locationId( $vendor['vendor_id'] ), self::fixed( new Coordinates( 53.7997, -1.5492 ) ) ) );

		$store = $this->data( $this->call( 'GET', '/me/store' ), 200 );
		$this->assertSame( array( 53.7997, -1.5492, 'gcwfh9' ), array( $store['coordinates']['latitude'], $store['coordinates']['longitude'], $store['coordinates']['geo_cell'] ) );
	}

	/**
	 * Answers requests with the next answer.
	 *
	 * @param false|array<string, mixed> $preempt Whether to short-circuit.
	 * @param array<string, mixed>       $args    Request arguments.
	 * @param string                     $url     URL.
	 * @return array<string, mixed>
	 */
	public function intercept( false|array $preempt, array $args, string $url ): array {
		$this->urls[] = $url;

		return array(
			'headers'  => array(),
			'body'     => $this->answer[1],
			'response' => array(
				'code'    => $this->answer[0],
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * A vendor's default location ID.
	 *
	 * @param int $vendor_id Vendor.
	 */
	private function locationId( int $vendor_id ): int {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE vendor_id = %d AND is_default = 1', $wpdb->prefix . 'mercatus_fulfilment_locations', $vendor_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test lookup.
	}

	/**
	 * A geocoder that always gives the same answer.
	 *
	 * @param Coordinates|null $point Answer.
	 */
	private static function fixed( ?Coordinates $point ): Geocoder {
		return new class( $point ) implements Geocoder {

			/**
			 * Creates it.
			 *
			 * @param Coordinates|null $point Answer.
			 */
			public function __construct( private ?Coordinates $point ) {}

			public function geocode( array $address ): ?Coordinates {
				return $this->point;
			}
		};
	}

	/**
	 * Outbound HTTP that resolves every host to a public address.
	 */
	private static function http(): OutboundHttp {
		return new OutboundHttp( static fn( string $host ): array => '' === $host ? array() : array( '93.184.216.34' ) );
	}

	/**
	 * An address in Leeds.
	 *
	 * @return array<string, string>
	 */
	private static function address(): array {
		return array(
			'address_1' => '12 Market Street',
			'city'      => 'Leeds',
			'postcode'  => 'LS1 6DT',
			'country'   => 'GB',
		);
	}
}
