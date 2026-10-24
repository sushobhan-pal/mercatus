<?php
/**
 * What a migration run did (KER-5).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Kernel\Migrations;

/**
 * Applied versions, drifted modules and failures, by module ID.
 */
final class MigrationReport {

	public bool $locked = false;

	/**
	 * Applied versions by module ID.
	 *
	 * @var array<string, list<int>>
	 */
	public array $applied = array();

	/**
	 * The first drifted version by module ID: an applied migration whose file changed or disappeared.
	 *
	 * @var array<string, int>
	 */
	public array $drifted = array();

	/**
	 * The version that failed by module ID.
	 *
	 * @var array<string, int>
	 */
	public array $failed = array();

	/**
	 * Whether every module is now up to date.
	 */
	public function succeeded(): bool {
		return ! $this->locked && array() === $this->drifted && array() === $this->failed;
	}
}
