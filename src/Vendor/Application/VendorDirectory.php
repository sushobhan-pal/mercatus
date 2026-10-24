<?php
/**
 * Vendors and applications, as the endpoints see them (VEN-11, VEN-12, VEN-17).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Vendor\Application;

use Mercatus\Core\Shared\Http\ListRequest;

/**
 * Infrastructure implements it over the tables, WooCommerce accounts and the outbox. Results come back
 * serialized for the API; failures are domain errors.
 */
interface VendorDirectory {

	/**
	 * Registers a visitor or a signed-in customer and submits their application (VEN-12).
	 *
	 * @param array<string, mixed> $input Account fields (visitors), store name, fixed fields and answers.
	 * @return array{application: array<string, mixed>, account_created: bool}
	 */
	public function register( array $input ): array;

	/**
	 * One page of the review queue.
	 *
	 * @param ListRequest $request Validated list request.
	 * @return list<array{id: int, version: int, application: array<string, mixed>}>
	 */
	public function applications( ListRequest $request ): array;

	/**
	 * An attempt with the applicant's earlier attempts and what changed, if the current actor may see it.
	 *
	 * @param int $id Attempt ID.
	 * @return array<string, mixed>
	 */
	public function application( int $id ): array;

	/**
	 * Approves a pending attempt.
	 *
	 * @param int $id Attempt ID.
	 * @return array<string, mixed>
	 */
	public function approve( int $id ): array;

	/**
	 * Rejects a pending attempt with a reason.
	 *
	 * @param int    $id     Attempt ID.
	 * @param string $reason Reason, shown to the applicant.
	 * @return array<string, mixed>
	 */
	public function reject( int $id, string $reason ): array;

	/**
	 * One page of vendors.
	 *
	 * @param ListRequest $request Validated list request.
	 * @return list<array{id: int, version: int, vendor: array<string, mixed>}>
	 */
	public function vendors( ListRequest $request ): array;

	/**
	 * Suspends an active vendor with a reason.
	 *
	 * @param int    $id     Vendor ID.
	 * @param string $reason Reason, shown to the vendor.
	 * @return array<string, mixed>
	 */
	public function suspend( int $id, string $reason ): array;

	/**
	 * Reinstates a suspended vendor.
	 *
	 * @param int $id Vendor ID.
	 * @return array<string, mixed>
	 */
	public function reinstate( int $id ): array;

	/**
	 * Where a user stands: their vendor and their latest application, for /me.
	 *
	 * @param int $user_id User.
	 * @return array{vendor: array{id: int, status: string}|null, application: array{id: int, attempt: int, status: string, reason: string|null}|null}
	 */
	public function standing( int $user_id ): array;

	/**
	 * The registration form in force, in the current language: the questions after the store name, the terms
	 * page when one is set, and whether WooCommerce generates visitors' passwords. A choice's value is the
	 * administrator's original text; its label is translated (VEN-15).
	 *
	 * @return array{questions: list<array{id: string, label: string, type: string, required: bool, choices: list<array{value: string, label: string}>}>, terms: array{id: int, title: string, url: string}|null, generate_password: bool}
	 */
	public function registrationForm(): array;
}
