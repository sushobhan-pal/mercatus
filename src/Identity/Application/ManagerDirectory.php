<?php
/**
 * Marketplace managers, for the API layer (IDN-15, VEN-16).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Identity\Application;

use Mercatus\Core\Shared\Errors\DomainError;

/**
 * The port the managers endpoints call (IDN-21), so they never reach Infrastructure.
 */
interface ManagerDirectory {

	/**
	 * The appointed managers.
	 *
	 * @return list<array{user_id: int, display_name: string, email: string}>
	 */
	public function managers(): array;

	/**
	 * Makes a user a marketplace manager. Repeating it changes nothing.
	 *
	 * @param int $user_id WordPress user.
	 * @return array{user_id: int, display_name: string, email: string}
	 * @throws DomainError When the user doesn't exist.
	 */
	public function appoint( int $user_id ): array;

	/**
	 * Ends a user's appointment. Repeating it changes nothing.
	 *
	 * @param int $user_id WordPress user.
	 * @throws DomainError When the user doesn't exist.
	 */
	public function remove( int $user_id ): void;
}
