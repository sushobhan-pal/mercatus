<?php
/**
 * One vendor application attempt (VEN-11).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Vendor\Domain;

/**
 * Never overwritten by a resubmission: the next attempt is a new row.
 */
final readonly class VendorApplication {

	/**
	 * Creates the attempt.
	 *
	 * @param int                                                                         $id        ID.
	 * @param int                                                                         $userId    Applicant.
	 * @param int                                                                         $attempt   1 for the first, then 2, …
	 * @param string                                                                      $status    pending, approved, rejected or withdrawn.
	 * @param string                                                                      $storeName Requested store name.
	 * @param list<array{id: string, label: string, type: string, answer: string|bool|null}> $answers   Snapshot of the answers.
	 * @param string|null                                                                 $reason    Why it was rejected.
	 * @param int|null                                                                    $decidedBy Who decided; 0 for the system (automatic approval).
	 * @param string|null                                                                 $decidedAt When.
	 * @param string                                                                      $createdAt When it was submitted.
	 * @param string                                                                      $updatedAt When it last changed.
	 * @param int                                                                         $version   Version (PLAT-9).
	 */
	public function __construct(
		public int $id,
		public int $userId,
		public int $attempt,
		public string $status,
		public string $storeName,
		public array $answers,
		public ?string $reason,
		public ?int $decidedBy,
		public ?string $decidedAt,
		public string $createdAt,
		public string $updatedAt,
		public int $version,
	) {}

	/**
	 * The attempt as the API returns it.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'id'         => $this->id,
			'user_id'    => $this->userId,
			'attempt'    => $this->attempt,
			'status'     => $this->status,
			'store_name' => $this->storeName,
			'answers'    => $this->answers,
			'reason'     => $this->reason,
			'decided_by' => $this->decidedBy,
			'decided_at' => null === $this->decidedAt ? null : self::iso( $this->decidedAt ),
			'created_at' => self::iso( $this->createdAt ),
			'updated_at' => self::iso( $this->updatedAt ),
			'version'    => $this->version,
		);
	}

	/**
	 * A stored UTC time in ISO 8601.
	 *
	 * @param string $time Stored time.
	 */
	public static function iso( string $time ): string {
		return str_replace( ' ', 'T', $time ) . 'Z';
	}
}
