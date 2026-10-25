<?php
/**
 * core.store's slice of the scale data set (core.store §9, PLAT-10, KER-12).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

use Mercatus\Core\Database\Seeders\SeedContext;
use Mercatus\Core\Database\Seeders\SeedStep;
use Mercatus\Core\Kernel\Kernel;
use Mercatus\Core\Shared\Clock\Clock;
use Mercatus\Core\Store\Domain\Geohash;
use Mercatus\Core\Store\Infrastructure\LocationRepository;
use Mercatus\Core\Store\Infrastructure\StoreRepository;
use Mercatus\Core\Store\Infrastructure\Stores;

/**
 * core.vendor's step already created a store per seeded vendor through its approval (STO-1). This step opens
 * each store and fills in its profile and address as its vendor would, through the store's own change command,
 * then sets the coordinates a geocoder would find: a city from a short list, spread a few kilometres, so
 * `geo_cell` varies (CAT-6).
 */
return new class() implements SeedStep {

	/**
	 * Cities: name, state, country, latitude, longitude.
	 */
	private const CITIES = array(
		array( 'Leeds', 'West Yorkshire', 'GB', 53.7997, -1.5492 ),
		array( 'Lyon', 'Auvergne-Rhône-Alpes', 'FR', 45.7640, 4.8357 ),
		array( 'Pune', 'Maharashtra', 'IN', 18.5204, 73.8567 ),
		array( 'Austin', 'TX', 'US', 30.2672, -97.7431 ),
		array( 'Melbourne', 'VIC', 'AU', -37.8136, 144.9631 ),
		array( 'Porto', 'Porto', 'PT', 41.1579, -8.6291 ),
	);

	public function run( SeedContext $context ): void {
		$services = ( Kernel::instance() ?? throw new RuntimeException( 'The site kernel did not boot.' ) )->container();
		$stores   = $services->get( Stores::class );
		try {
			$context->inBatches(
				$context->count( 'vendors' ),
				100,
				function ( int $offset, int $size ) use ( $context, $stores, $services ): void {
					for ( $n = $offset + 1; $n <= $offset + $size; $n++ ) {
						$this->store( $context, $stores, $services->get( StoreRepository::class ), $services->get( LocationRepository::class ), $services->get( Clock::class ), $n );
					}
				}
			);
		} finally {
			wp_set_current_user( 0 );
		}
	}

	/**
	 * Fills in one seeded vendor's store.
	 *
	 * @param SeedContext        $context   Randomness.
	 * @param Stores             $stores    Store commands.
	 * @param StoreRepository    $repo      Stores, to find the location.
	 * @param LocationRepository $locations Locations.
	 * @param Clock              $clock     Clock.
	 * @param int                $n         Vendor number, from 1.
	 * @throws RuntimeException When the seeded vendor or their store is missing.
	 */
	private function store( SeedContext $context, Stores $stores, StoreRepository $repo, LocationRepository $locations, Clock $clock, int $n ): void {
		$user = get_user_by( 'login', sprintf( 'seed-vendor-%04d', $n ) );
		if ( ! $user instanceof WP_User ) {
			throw new RuntimeException( 'core.vendor\'s step must run first.' );
		}
		wp_set_current_user( $user->ID );
		$mine = $stores->mine();
		$city = self::CITIES[ $context->random->getInt( 0, count( self::CITIES ) - 1 ) ];
		$stores->updateMine(
			array(
				'visibility'     => 'public',
				'tagline'        => 'Seeded for the scale suite',
				'description'    => '<p>Store ' . $n . ' sells things in ' . $city[0] . '.</p>',
				'contact_email'  => sprintf( 'seed-store-%04d@example.test', $n ),
				'policy_returns' => 'Returns within 30 days.',
				'address'        => array(
					'address_1' => $context->random->getInt( 1, 200 ) . ' Seed Street',
					'city'      => $city[0],
					'state'     => $city[1],
					'country'   => $city[2],
				),
			),
			$mine['version']
		);
		$pair = $repo->byId( (int) $mine['store']['id'] );
		if ( null === $pair || null === $pair[1] ) {
			throw new RuntimeException( 'The seeded store has no default location.' );
		}
		$latitude  = $city[3] + $context->random->getInt( -300, 300 ) / 10000;
		$longitude = $city[4] + $context->random->getInt( -300, 300 ) / 10000;
		$locations->setCoordinates( $pair[1]->id, null, $latitude, $longitude, Geohash::encode( $latitude, $longitude ), $clock->now() );
	}
};
