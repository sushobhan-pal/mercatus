<?php
/**
 * Resolves and caches identities (SEC-2, IDN-2).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Identity\Application;

use Mercatus\Core\Contracts\Cache\CachePort;
use Mercatus\Core\Contracts\Identity\ActorIdentity;
use Mercatus\Core\Contracts\Identity\IdentityRevisions;
use Mercatus\Core\Contracts\Settings\SettingsReader;
use Mercatus\Core\Identity\Domain\IdentityAssembler;

/**
 * Per request, then in `mercatus_actors` for five minutes, keyed by the user's identity revision (PLAT-5).
 * The key also carries a hash of the managers' shared permissions, so changing them takes effect at once.
 */
final class IdentityResolver implements IdentityRevisions {

	private const GROUP = 'mercatus_actors';

	private const TTL = 300;

	/**
	 * Identities resolved in this request, by user ID.
	 *
	 * @var array<int, ActorIdentity>
	 */
	private array $resolved = array();

	/**
	 * Creates the resolver.
	 *
	 * @param CachePort        $cache    Cache.
	 * @param ModuleExtensions $modules  Claim sources.
	 * @param SettingsReader   $settings Settings.
	 */
	public function __construct( private CachePort $cache, private ModuleExtensions $modules, private SettingsReader $settings ) {}

	/**
	 * A user's identity; anonymous for user 0.
	 *
	 * @param int $user_id WordPress user.
	 */
	public function resolve( int $user_id ): ActorIdentity {
		if ( $user_id <= 0 ) {
			return ActorIdentity::anonymous();
		}

		return $this->resolved[ $user_id ] ??= $this->cached( $user_id );
	}

	public function bump( int $user_id ): void {
		unset( $this->resolved[ $user_id ] );
		$this->cache->bumpRevision( self::scope( $user_id ) );
	}

	/**
	 * The identity from the cache, or from the claim sources on a miss.
	 *
	 * @param int $user_id WordPress user.
	 */
	private function cached( int $user_id ): ActorIdentity {
		$key  = sprintf( 'u%d:r%d:p%s', $user_id, $this->cache->revision( self::scope( $user_id ) ), hash( 'crc32b', $this->settings->string( IdentitySettings::MANAGER_PERMISSIONS ) ) );
		$data = $this->cache->remember( self::GROUP, $key, self::TTL, fn(): array => $this->fromSources( $user_id )->toArray() );

		return ActorIdentity::fromArray( $data ) ?? $this->fromSources( $user_id );
	}

	/**
	 * The identity from every claim source.
	 *
	 * @param int $user_id WordPress user.
	 */
	private function fromSources( int $user_id ): ActorIdentity {
		$claims = array();
		foreach ( $this->modules->claimSources() as $source ) {
			array_push( $claims, ...$source->claims( $user_id ) );
		}

		return IdentityAssembler::assemble( $user_id, $claims );
	}

	/**
	 * A user's revision scope.
	 *
	 * @param int $user_id WordPress user.
	 */
	private static function scope( int $user_id ): string {
		return 'actor:' . $user_id;
	}
}
