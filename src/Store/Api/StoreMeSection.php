<?php
/**
 * The store summary in /me (IDN-12, STO-13).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Store\Api;

use Mercatus\Core\Contracts\Identity\ActorIdentity;
use Mercatus\Core\Contracts\Identity\MeSectionProvider;
use Mercatus\Core\Store\Application\StoreDirectory;

/**
 * The actor's store, so the dashboard can show where it stands and prompt a vendor whose store is still
 * hidden to open it; nothing for an actor without a store. Read from the cache, so a warm /me stays at no
 * query (PLAT-10).
 */
final class StoreMeSection implements MeSectionProvider {

	/**
	 * Creates the section.
	 *
	 * @param StoreDirectory $stores Stores.
	 */
	public function __construct( private StoreDirectory $stores ) {}

	public function id(): string {
		return 'store';
	}

	public function section( ActorIdentity $actor ): ?array {
		return null === $actor->vendorId ? null : $this->stores->summary( $actor->vendorId );
	}
}
