<?php
/**
 * core.store's commands and queries (STO-1 to STO-9, STO-13, STO-15).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Store\Infrastructure;

use Mercatus\Core\Contracts\Cache\CachePort;
use Mercatus\Core\Contracts\Identity\AccessDecision;
use Mercatus\Core\Contracts\Identity\CurrentActor;
use Mercatus\Core\Contracts\Location\Geocoder;
use Mercatus\Core\Contracts\Settings\SettingsReader;
use Mercatus\Core\Shared\Audit\AuditLog;
use Mercatus\Core\Shared\Clock\Clock;
use Mercatus\Core\Shared\Errors\DomainError;
use Mercatus\Core\Shared\Events\EventLane;
use Mercatus\Core\Shared\Events\EventPublisher;
use Mercatus\Core\Shared\Http\ListRequest;
use Mercatus\Core\Shared\Persistence\TransactionRunner;
use Mercatus\Core\Store\Application\StoreAccess;
use Mercatus\Core\Store\Application\StoreDirectory;
use Mercatus\Core\Store\Application\StorePolicy;
use Mercatus\Core\Store\Application\StoreSerializer;
use Mercatus\Core\Store\Application\StoreSettings;
use Mercatus\Core\Store\Domain\Geohash;
use Mercatus\Core\Store\Domain\Location;
use Mercatus\Core\Store\Domain\Store;
use Mercatus\Core\Store\Domain\StoreProfile;
use Mercatus\Core\Store\Domain\StoreSlugNormalizer;
use Mercatus\Core\Vendor\Application\VendorSettings;

/**
 * Each change writes the store, its event and its audit entry in one transaction (API-3), then bumps the
 * store's cache revision. Reads come from `mercatus_stores`: a store under its own revision, and the slug and
 * vendor maps under theirs, so a warm store page costs no query and a cold one costs one (STO-7).
 */
final class Stores implements StoreDirectory {

	private const GROUP = 'mercatus_stores';

	private const TTL = 3600;

	/**
	 * The rich-text allow-list for descriptions (STO-5), applied on save and again on output.
	 */
	public const RICH_TEXT = array(
		'p'      => array(),
		'br'     => array(),
		'strong' => array(),
		'em'     => array(),
		'b'      => array(),
		'i'      => array(),
		'ul'     => array(),
		'ol'     => array(),
		'li'     => array(),
		'a'      => array(
			'href'  => true,
			'title' => true,
		),
	);

	/**
	 * Store status by vendor event (STO-2).
	 */
	public const FOLLOWS = array(
		'vendor.account.suspended'  => Store::SUSPENDED,
		'vendor.account.reinstated' => Store::ACTIVE,
		'vendor.account.closed'     => Store::CLOSED,
	);

	/**
	 * Creates the service.
	 *
	 * @param StoreRepository    $stores       Stores with their default locations.
	 * @param LocationRepository $locations    Locations.
	 * @param StorePolicy        $policy       Who may see and change a store.
	 * @param TransactionRunner  $transactions Transactions.
	 * @param EventPublisher     $events       Events.
	 * @param AuditLog           $audit        Audit log.
	 * @param CurrentActor       $actors       The current actor.
	 * @param SettingsReader     $settings     Settings.
	 * @param Clock              $clock        Clock.
	 * @param CachePort          $cache        Cache.
	 * @param StoreUrls          $urls         Store page addresses.
	 */
	public function __construct(
		private StoreRepository $stores,
		private LocationRepository $locations,
		private StorePolicy $policy,
		private TransactionRunner $transactions,
		private EventPublisher $events,
		private AuditLog $audit,
		private CurrentActor $actors,
		private SettingsReader $settings,
		private Clock $clock,
		private CachePort $cache,
		private StoreUrls $urls,
	) {}

	/**
	 * Creates a vendor's store and its default location from the name they applied with. Repeating it creates
	 * nothing more (STO-1).
	 *
	 * @param int    $vendor_id Vendor.
	 * @param string $name      Store name from the approved application.
	 */
	public function createForVendor( int $vendor_id, string $name ): void {
		if ( null !== $this->stores->byVendorId( $vendor_id ) ) {
			return;
		}
		$name       = '' === trim( $name ) ? sprintf( 'Store %d', $vendor_id ) : mb_substr( trim( $name ), 0, StoreProfile::NAME_MAX );
		$base       = StoreSlugNormalizer::fromName( $name, $this->reservedSlugs() );
		$taken      = $this->stores->slugsLike( $base );
		$slug       = $base;
		$visibility = $this->settings->string( StoreSettings::NEW_STORE_VISIBILITY );
		$n          = 1;
		while ( in_array( $slug, $taken, true ) ) {
			$slug = StoreSlugNormalizer::candidate( $base, ++$n );
		}

		$this->transactions->run(
			function () use ( $vendor_id, $name, $slug, $visibility ): void {
				$now      = $this->clock->now();
				$id       = $this->stores->insert( $vendor_id, $name, $slug, $visibility, $now );
				$location = $this->locations->insertDefault( $vendor_id, $now );
				$this->events->publish(
					'store.store.created',
					'store',
					$id,
					1,
					array(
						'store_id'   => $id,
						'vendor_id'  => $vendor_id,
						'visibility' => $visibility,
					)
				);
				$this->events->publish(
					'store.location.created',
					'location',
					$location,
					1,
					array(
						'location_id' => $location,
						'vendor_id'   => $vendor_id,
						'is_default'  => true,
					)
				);
				$this->audit->record( 'store.created', 'store', $id, array( 'vendor_id' => $vendor_id ) );
				$this->audit->record( 'location.created', 'location', $location, array( 'vendor_id' => $vendor_id ) );
			}
		);
		$this->cache->bumpRevision( self::slugScope( $slug ) );
		$this->cache->bumpRevision( self::vendorScope( $vendor_id ) );
	}

	/**
	 * Moves a vendor's store to the status its vendor's event implies (STO-2).
	 *
	 * @param int    $vendor_id Vendor.
	 * @param string $status    active, suspended or closed.
	 */
	public function followVendor( int $vendor_id, string $status ): void {
		$pair = $this->stores->byVendorId( $vendor_id );
		if ( null === $pair ) {
			return;
		}
		$store = $pair[0];
		$this->transactions->run(
			function () use ( $store, $status ): void {
				if ( $this->stores->setStatus( $store->id, $status, $this->clock->now() ) ) {
					$this->events->publish(
						'store.store.status_changed',
						'store',
						$store->id,
						$store->version + 1,
						array(
							'store_id' => $store->id,
							'status'   => $status,
						)
					);
					$this->audit->record( 'store.status_changed', 'store', $store->id, array( 'status' => $status ) );
				}
			}
		);
		$this->changed( $store->id );
	}

	public function listed( ListRequest $request, string $search ): array {
		$public = new ListRequest(
			$request->sort,
			$request->direction,
			$request->perPage,
			$request->afterValue,
			$request->afterId,
			array(
				'visibility' => Store::PUBLIC,
				'status'     => Store::ACTIVE,
			)
		);

		return array_map( fn( array $pair ): array => self::listRow( $pair, $this->publicView( $pair ) ), $this->stores->page( $public, $search ) );
	}

	public function all( ListRequest $request, string $search ): array {
		$actor = $this->actors->identity();
		if ( ! $actor->holds( StoreAccess::MANAGE ) ) {
			throw DomainError::of( 'mercatus_store_filter_not_allowed' );
		}
		$rows = array();
		foreach ( $this->stores->page( $request, $search ) as $pair ) {
			if ( AccessDecision::Allowed === $this->policy->decide( $actor, StorePolicy::MANAGE, $pair[0] ) ) {
				$rows[] = self::listRow( $pair, $this->fullView( $pair ) );
			}
		}

		return $rows;
	}

	public function public( string $store ): array {
		$pair = $this->resolve( $store );
		if ( null === $pair || ( ! $pair[0]->isListed() && AccessDecision::Allowed !== $this->policy->decide( $this->actors->identity(), StorePolicy::VIEW, $pair[0] ) ) ) {
			throw DomainError::of( 'mercatus_store_unknown', array( 'store' => $store ) );
		}

		return array(
			'store'   => $this->publicView( $pair ),
			'version' => $pair[0]->version,
			'listed'  => $pair[0]->isListed(),
		);
	}

	public function edit( string $store ): array {
		$pair     = $this->resolve( $store );
		$decision = null === $pair ? AccessDecision::NotFound : $this->policy->decide( $this->actors->identity(), StorePolicy::MANAGE, $pair[0] );
		if ( null === $pair || AccessDecision::NotFound === $decision ) {
			throw DomainError::of( 'mercatus_store_unknown', array( 'store' => $store ) );
		}
		if ( AccessDecision::Allowed !== $decision ) {
			throw DomainError::of( 'mercatus_store_context_not_allowed' );
		}

		return $this->full( $pair );
	}

	public function mine(): array {
		return $this->full( $this->own() );
	}

	public function updateMine( array $changes, int $expected ): array {
		return $this->apply( $this->own(), $changes, $expected, true );
	}

	public function update( string $store, array $changes, int $expected ): array {
		return $this->apply( $this->allowed( $this->resolve( $store ), StorePolicy::MANAGE ), $changes, $expected, false );
	}

	public function summary( int $vendor_id ): ?array {
		$id   = $this->idForVendor( $vendor_id );
		$pair = $id > 0 ? $this->cached( $id ) : null;

		return null === $pair ? null : StoreSerializer::summary( $pair[0] );
	}

	/**
	 * Geocodes a location's current address and records the coordinates, unless the address changed while the
	 * provider was asked (STO-14). Never throws for a provider's failure.
	 *
	 * @param int      $location_id Location ID.
	 * @param Geocoder $geocoder    The chosen geocoder.
	 * @return bool Whether coordinates were recorded.
	 */
	public function geocode( int $location_id, Geocoder $geocoder ): bool {
		$location = $this->locations->byId( $location_id );
		if ( null === $location || '' === implode( '', $location->address ) ) {
			return false;
		}
		$point = $geocoder->geocode( $location->address );
		if ( null === $point || ! $this->locations->setCoordinates( $location->id, $location->version, $point->latitude, $point->longitude, Geohash::encode( $point->latitude, $point->longitude ), $this->clock->now() ) ) {
			return false;
		}
		$pair = $this->stores->byVendorId( $location->vendorId );
		if ( null !== $pair ) {
			$this->changed( $pair[0]->id );
		}

		return true;
	}

	/**
	 * Clears a closed store's contact details and address, for WordPress's eraser (SEC-7). An open store keeps
	 * them, because buyers and orders rely on them.
	 *
	 * @param int $vendor_id Vendor.
	 * @return bool|null True when erased, false when kept, null when the vendor has no store.
	 */
	public function erasePersonalData( int $vendor_id ): ?bool {
		$pair = $this->stores->byVendorId( $vendor_id );
		if ( null === $pair ) {
			return null;
		}
		list( $store, $location ) = $pair;
		if ( Store::CLOSED !== $store->status ) {
			return false;
		}
		$this->transactions->run(
			function () use ( $store, $location ): void {
				$now = $this->clock->now();
				$this->stores->eraseContact( $store->id, $now );
				if ( null !== $location ) {
					$this->locations->updateAddress( $location->id, array_fill_keys( array_keys( StoreProfile::ADDRESS ), '' ), $now );
				}
				$this->audit->record( 'store.contact_erased', 'store', $store->id );
			}
		);
		$this->changed( $store->id );

		return true;
	}

	/**
	 * A vendor's store with its default location, uncached, for WordPress's exporter.
	 *
	 * @param int $vendor_id Vendor.
	 * @return array<string, mixed>|null Every field, or null without a store.
	 */
	public function export( int $vendor_id ): ?array {
		$pair = $this->stores->byVendorId( $vendor_id );

		return null === $pair ? null : $this->fullView( $pair );
	}

	/**
	 * Applies a change to a store the actor may change.
	 *
	 * @param array{0: Store, 1: Location|null} $pair      The store and its default location.
	 * @param array<string, mixed>              $changes   Fields to change.
	 * @param int                               $expected  Version the change was based on.
	 * @param bool                              $by_vendor Whether the vendor is changing their own store.
	 * @return array{store: array<string, mixed>, version: int}
	 * @throws DomainError When a field doesn't fit, the slug is locked or taken, or the store changed meanwhile.
	 */
	private function apply( array $pair, array $changes, int $expected, bool $by_vendor ): array {
		list( $store, $location ) = $pair;
		$valid                    = StoreProfile::validate( $this->clean( $changes ) );
		if ( isset( $valid['slug'] ) && $valid['slug'] === $store->slug ) {
			unset( $valid['slug'] );
		}
		if ( isset( $valid['slug'] ) ) {
			if ( $by_vendor && ! $this->settings->bool( StoreSettings::VENDORS_CHANGE_SLUG ) ) {
				throw DomainError::of( 'mercatus_store_slug_locked' );
			}
			StoreSlugNormalizer::assertValid( $valid['slug'], $this->reservedSlugs() );
			if ( null !== $this->stores->bySlug( $valid['slug'] ) ) {
				throw DomainError::of( 'mercatus_store_slug_taken', array( 'field' => 'slug' ) );
			}
		}
		if ( $store->version !== $expected ) {
			throw DomainError::of( 'mercatus_version_conflict', array( 'store_id' => $store->id ) );
		}
		$address = $valid['address'] ?? array();
		unset( $valid['address'] );
		$fields = array_keys( array_diff_key( $valid, array( 'visibility' => true ) ) );
		$shown  = isset( $valid['visibility'] ) && $valid['visibility'] !== $store->visibility;

		$this->transactions->run(
			function () use ( $store, $location, $valid, $address, $expected, $fields, $shown ): void {
				$now = $this->clock->now();
				if ( ! $this->stores->update( $store->id, $expected, $valid, $now ) ) {
					throw DomainError::of( 'mercatus_version_conflict', array( 'store_id' => $store->id ) );
				}
				if ( array() !== $address ) {
					$location_id = $location->id ?? $this->locations->insertDefault( $store->vendorId, $now );
					$this->locations->updateAddress( $location_id, $address, $now );
					// On the bulk lane: geocoding calls a provider, which never holds up the save (STO-14).
					$this->events->publish(
						'store.location.updated',
						'location',
						$location_id,
						( $location->version ?? 1 ) + 1,
						array(
							'location_id' => $location_id,
							'vendor_id'   => $store->vendorId,
							'fields'      => array_keys( $address ),
						),
						1,
						EventLane::Bulk,
						'location:' . $location_id
					);
					$this->audit->record( 'location.updated', 'location', $location_id, array( 'fields' => implode( ',', array_keys( $address ) ) ) );
				}
				if ( array() !== $fields ) {
					$this->events->publish(
						'store.store.updated',
						'store',
						$store->id,
						$expected + 1,
						array(
							'store_id' => $store->id,
							'fields'   => $fields,
						)
					);
					$this->audit->record( 'store.updated', 'store', $store->id, array( 'fields' => implode( ',', $fields ) ) );
				}
				if ( $shown ) {
					$this->events->publish(
						'store.store.visibility_changed',
						'store',
						$store->id,
						$expected + 1,
						array(
							'store_id'   => $store->id,
							'visibility' => $valid['visibility'],
						)
					);
					$this->audit->record( 'store.visibility_changed', 'store', $store->id, array( 'visibility' => (string) $valid['visibility'] ) );
				}
			}
		);
		$this->changed( $store->id );
		if ( isset( $valid['slug'] ) ) {
			$this->cache->bumpRevision( self::slugScope( $store->slug ) );
			$this->cache->bumpRevision( self::slugScope( (string) $valid['slug'] ) );
		}

		return $this->full( $this->cached( $store->id ) ?? $pair );
	}

	/**
	 * Cleans text before it's checked (STO-5): the description keeps the rich-text allow-list, with links marked
	 * nofollow and ugc; every other field is plain text. A contact email must be an email address.
	 *
	 * @param array<string, mixed> $changes Fields to change.
	 * @return array<string, mixed>
	 * @throws DomainError When the contact email isn't an email address.
	 */
	private function clean( array $changes ): array {
		foreach ( $changes as $field => $value ) {
			if ( 'address' === $field && is_array( $value ) ) {
				$changes[ $field ] = array_map( static fn( mixed $text ): mixed => is_string( $text ) ? sanitize_text_field( $text ) : $text, $value );
				if ( isset( $changes[ $field ]['country'] ) && is_string( $changes[ $field ]['country'] ) ) {
					$changes[ $field ]['country'] = strtoupper( $changes[ $field ]['country'] );
				}
				continue;
			}
			if ( ! is_string( $value ) ) {
				continue;
			}
			$changes[ $field ] = match ( true ) {
				'description' === $field             => wp_unslash( wp_rel_ugc( wp_slash( wp_kses( $value, self::RICH_TEXT ) ) ) ),
				str_starts_with( $field, 'policy_' ) => sanitize_textarea_field( $value ),
				default                              => sanitize_text_field( $value ),
			};
			if ( 'contact_email' === $field && '' !== $changes[ $field ] && false === is_email( $changes[ $field ] ) ) {
				throw DomainError::of( 'mercatus_store_invalid', array( 'field' => 'contact_email' ) );
			}
		}

		return $changes;
	}

	/**
	 * The current vendor's store, which they may change.
	 *
	 * @return array{0: Store, 1: Location|null}
	 * @throws DomainError When the actor has no store.
	 */
	private function own(): array {
		$vendor_id = $this->actors->identity()->vendorId;
		$id        = null === $vendor_id ? 0 : $this->idForVendor( $vendor_id );

		return $this->allowed( $id > 0 ? $this->cached( $id ) : null, StorePolicy::EDIT );
	}

	/**
	 * A store the current actor may act on.
	 *
	 * @param array{0: Store, 1: Location|null}|null $pair   The store, if it exists.
	 * @param string                                 $action StorePolicy action.
	 * @return array{0: Store, 1: Location|null}
	 * @throws DomainError When it doesn't exist or the actor may not act on it (mercatus_store_unknown).
	 */
	private function allowed( ?array $pair, string $action ): array {
		if ( null === $pair || AccessDecision::Allowed !== $this->policy->decide( $this->actors->identity(), $action, $pair[0] ) ) {
			throw DomainError::of( 'mercatus_store_unknown' );
		}

		return $pair;
	}

	/**
	 * A store and its location from the cache, or from one query on a miss.
	 *
	 * @param int $id Store ID.
	 * @return array{0: Store, 1: Location|null}|null
	 */
	private function cached( int $id ): ?array {
		$data = $this->cache->remember(
			self::GROUP,
			'store:' . $id . ':r' . $this->cache->revision( self::storeScope( $id ) ),
			self::TTL,
			fn(): array => self::pack( $this->stores->byId( $id ) )
		);

		return self::unpack( $data );
	}

	/**
	 * A store by ID in digits, or else by slug (STO-9: a slug is never all digits).
	 *
	 * @param string $store Slug or ID.
	 * @return array{0: Store, 1: Location|null}|null
	 */
	private function resolve( string $store ): ?array {
		return ctype_digit( $store ) ? $this->cached( (int) $store ) : $this->cachedBySlug( $store );
	}

	/**
	 * A store by slug, through the slug map. An unknown slug is remembered too, until a store takes it.
	 *
	 * @param string $slug Slug.
	 * @return array{0: Store, 1: Location|null}|null
	 */
	private function cachedBySlug( string $slug ): ?array {
		$key = 'slug:' . $slug . ':r' . $this->cache->revision( self::slugScope( $slug ) );
		$id  = $this->cache->get( self::GROUP, $key );
		if ( is_int( $id ) ) {
			return $id > 0 ? $this->cached( $id ) : null;
		}
		$pair = $this->stores->bySlug( $slug );
		$this->remember( $key, $pair );

		return $pair;
	}

	/**
	 * A vendor's store ID through the vendor map; 0 before the store exists.
	 *
	 * @param int $vendor_id Vendor.
	 */
	private function idForVendor( int $vendor_id ): int {
		$key = 'vendor:' . $vendor_id . ':r' . $this->cache->revision( self::vendorScope( $vendor_id ) );
		$id  = $this->cache->get( self::GROUP, $key );
		if ( is_int( $id ) ) {
			return $id;
		}
		$pair = $this->stores->byVendorId( $vendor_id );
		$this->remember( $key, $pair );

		return null === $pair ? 0 : $pair[0]->id;
	}

	/**
	 * Remembers a map entry and, when found, the store it points to.
	 *
	 * @param string                                 $key  Map key.
	 * @param array{0: Store, 1: Location|null}|null $pair The store found, if any.
	 */
	private function remember( string $key, ?array $pair ): void {
		$this->cache->set( self::GROUP, $key, null === $pair ? 0 : $pair[0]->id, self::TTL );
		if ( null !== $pair ) {
			$this->cache->set( self::GROUP, 'store:' . $pair[0]->id . ':r' . $this->cache->revision( self::storeScope( $pair[0]->id ) ), self::pack( $pair ), self::TTL );
		}
	}

	/**
	 * Slugs no store may take: the dashboard page's (VEN-15).
	 *
	 * @return list<string>
	 */
	private function reservedSlugs(): array {
		$page = $this->settings->int( VendorSettings::DASHBOARD_PAGE );
		$slug = $page > 0 ? get_post_field( 'post_name', $page ) : '';

		return '' !== $slug ? array( $slug ) : array();
	}

	/**
	 * Drops a store's cached copy.
	 *
	 * @param int $id Store ID.
	 */
	private function changed( int $id ): void {
		$this->cache->bumpRevision( self::storeScope( $id ) );
	}

	/**
	 * A row for a store list.
	 *
	 * @param array{0: Store, 1: Location|null} $pair  The store.
	 * @param array<string, mixed>              $store How the actor sees it.
	 * @return array{id: int, version: int, name: string, store: array<string, mixed>}
	 */
	private static function listRow( array $pair, array $store ): array {
		return array(
			'id'      => $pair[0]->id,
			'version' => $pair[0]->version,
			'name'    => $pair[0]->name,
			'store'   => $store,
		);
	}

	/**
	 * Every field, with the version.
	 *
	 * @param array{0: Store, 1: Location|null} $pair The store.
	 * @return array{store: array<string, mixed>, version: int}
	 */
	private function full( array $pair ): array {
		return array(
			'store'   => $this->fullView( $pair ),
			'version' => $pair[0]->version,
		);
	}

	/**
	 * A store as the public sees it, with its page's address.
	 *
	 * @param array{0: Store, 1: Location|null} $pair The store.
	 * @return array<string, mixed>
	 */
	private function publicView( array $pair ): array {
		return StoreSerializer::public( ...$pair ) + array( 'url' => $this->urls->url( $pair[0]->slug ) );
	}

	/**
	 * Every field of a store, with its page's address.
	 *
	 * @param array{0: Store, 1: Location|null} $pair The store.
	 * @return array<string, mixed>
	 */
	private function fullView( array $pair ): array {
		return StoreSerializer::full( ...$pair ) + array( 'url' => $this->urls->url( $pair[0]->slug ) );
	}

	/**
	 * A store and its location as cacheable arrays.
	 *
	 * @param array{0: Store, 1: Location|null}|null $pair The store.
	 * @return array{store: array<string, mixed>|null, location: array<string, mixed>|null}
	 */
	private static function pack( ?array $pair ): array {
		return array(
			'store'    => null === $pair ? null : $pair[0]->toArray(),
			'location' => null === $pair || null === $pair[1] ? null : $pair[1]->toArray(),
		);
	}

	/**
	 * A store and its location from pack()'s output.
	 *
	 * @param mixed $data Cached data.
	 * @return array{0: Store, 1: Location|null}|null
	 */
	private static function unpack( mixed $data ): ?array {
		if ( ! is_array( $data ) || ! is_array( $data['store'] ?? null ) ) {
			return null;
		}

		return array( Store::fromArray( $data['store'] ), is_array( $data['location'] ?? null ) ? Location::fromArray( $data['location'] ) : null );
	}

	/**
	 * A store's revision scope.
	 *
	 * @param int $id Store ID.
	 */
	private static function storeScope( int $id ): string {
		return 'store:' . $id;
	}

	/**
	 * A slug's revision scope.
	 *
	 * @param string $slug Slug.
	 */
	private static function slugScope( string $slug ): string {
		return 'store-slug:' . $slug;
	}

	/**
	 * A vendor's store-map revision scope.
	 *
	 * @param int $vendor_id Vendor.
	 */
	private static function vendorScope( int $vendor_id ): string {
		return 'store-vendor:' . $vendor_id;
	}
}
