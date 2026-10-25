<?php
/**
 * Stores, for the API layer (IDN-21, STO-1 to STO-3).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Store\Application;

use Mercatus\Core\Shared\Errors\DomainError;
use Mercatus\Core\Shared\Http\ListRequest;

/**
 * The port the store endpoints and the `/me` section call, so they never reach Infrastructure. Every read and
 * change checks `StorePolicy` against the current actor.
 */
interface StoreDirectory {

	/**
	 * One page of listed stores, as the public sees them.
	 *
	 * @param ListRequest $request Validated list request; sorted by name or id.
	 * @param string      $search  Name prefix, or '' for all.
	 * @return list<array{id: int, version: int, name: string, store: array<string, mixed>}>
	 */
	public function listed( ListRequest $request, string $search ): array;

	/**
	 * One page of every store, hidden and suspended ones too, as administrators see them.
	 *
	 * @param ListRequest $request Validated list request, with visibility and status filters.
	 * @param string      $search  Name prefix, or '' for all.
	 * @return list<array{id: int, version: int, name: string, store: array<string, mixed>}>
	 * @throws DomainError When the actor may not manage stores.
	 */
	public function all( ListRequest $request, string $search ): array;

	/**
	 * A store by slug or ID, as the public sees it, and only when the actor may see it.
	 *
	 * @param string $store Slug, or ID in digits (a slug is never all digits, STO-9).
	 * @return array{store: array<string, mixed>, version: int, listed: bool}
	 * @throws DomainError When there's no such store for this actor (mercatus_store_unknown).
	 */
	public function public( string $store ): array;

	/**
	 * A store by slug or ID, every field (`context=edit`), for its owner, administrators and managers holding
	 * stores.manage.
	 *
	 * @param string $store Slug or ID.
	 * @return array{store: array<string, mixed>, version: int}
	 * @throws DomainError When there's no such store for this actor, or the actor may not edit it.
	 */
	public function edit( string $store ): array;

	/**
	 * The current vendor's own store, every field.
	 *
	 * @return array{store: array<string, mixed>, version: int}
	 * @throws DomainError When the actor has no store (mercatus_store_unknown).
	 */
	public function mine(): array;

	/**
	 * Changes the current vendor's store at the version If-Match named.
	 *
	 * @param array<string, mixed> $changes  Fields to change (StoreProfile::FIELDS).
	 * @param int                  $expected Version the change was based on.
	 * @return array{store: array<string, mixed>, version: int}
	 * @throws DomainError When a field doesn't fit, the slug is taken or locked, or the store changed meanwhile.
	 */
	public function updateMine( array $changes, int $expected ): array;

	/**
	 * Changes any store, for administrators and managers holding stores.manage.
	 *
	 * @param string               $store    Slug or ID.
	 * @param array<string, mixed> $changes  Fields to change (StoreProfile::FIELDS).
	 * @param int                  $expected Version the change was based on.
	 * @return array{store: array<string, mixed>, version: int}
	 * @throws DomainError As updateMine(), or when the actor may not manage the store.
	 */
	public function update( string $store, array $changes, int $expected ): array;

	/**
	 * What /me says about a vendor's store, or null before it exists.
	 *
	 * @param int $vendor_id Vendor.
	 * @return array{id: int, name: string, slug: string, visibility: string, status: string, listed: bool}|null
	 */
	public function summary( int $vendor_id ): ?array;
}
