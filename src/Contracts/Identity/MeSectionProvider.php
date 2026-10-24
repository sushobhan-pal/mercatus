<?php
/**
 * A section of GET /me (IDN-12, ARCH-8 point 5).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Contracts\Identity;

/**
 * Listed in a module's manifest (`meSections`). Each section is keyed by its ID under `sections`, and a module
 * returns null when it has nothing to say about the actor.
 */
interface MeSectionProvider {

	/**
	 * The section's key, for example vendor.
	 */
	public function id(): string;

	/**
	 * The section for an actor, or null.
	 *
	 * @param ActorIdentity $actor Actor.
	 * @return array<string, mixed>|null
	 */
	public function section( ActorIdentity $actor ): ?array;
}
