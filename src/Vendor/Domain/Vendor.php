<?php
/**
 * An approved vendor (VEN-1).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Vendor\Domain;

/**
 * Created by the vendor's first approval; its ID is the tenant ID other modules scope to (IDN-1).
 */
final readonly class Vendor {

	/**
	 * Creates the vendor.
	 *
	 * @param int         $id            ID.
	 * @param int         $userId        WordPress user.
	 * @param int         $applicationId The approved attempt.
	 * @param string      $status        active, suspended or closed.
	 * @param string|null $reason        Why it was suspended.
	 * @param string      $createdAt     When it was approved.
	 * @param string      $updatedAt     When it last changed.
	 * @param int         $version       Version (PLAT-9).
	 */
	public function __construct(
		public int $id,
		public int $userId,
		public int $applicationId,
		public string $status,
		public ?string $reason,
		public string $createdAt,
		public string $updatedAt,
		public int $version,
	) {}

	/**
	 * The vendor as the API returns it.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'id'             => $this->id,
			'user_id'        => $this->userId,
			'application_id' => $this->applicationId,
			'status'         => $this->status,
			'reason'         => $this->reason,
			'created_at'     => VendorApplication::iso( $this->createdAt ),
			'updated_at'     => VendorApplication::iso( $this->updatedAt ),
			'version'        => $this->version,
		);
	}
}
