<?php
/**
 * A projector over fixed rows that records what it's told to write.
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Fixtures\Projection;

use Mercatus\Core\Shared\Projection\Projector;

/**
 * For the projection runner's unit tests.
 */
final class RecordingProjector implements Projector {

	/**
	 * Rows written, or null when nothing was.
	 *
	 * @var array<string, array<string, string|null>>|null
	 */
	public ?array $upserts = null;

	/**
	 * Keys removed.
	 *
	 * @var list<string>
	 */
	public array $deletes = array();

	/**
	 * Creates the projector.
	 *
	 * @param array<string, array<string, string|null>> $rows   Rows it has.
	 * @param array<string, array<string, string|null>> $wanted Rows it should have.
	 */
	public function __construct( private array $rows, private array $wanted ) {}

	public function desired( int $key ): array {
		return $this->wanted;
	}

	public function stored( int $key ): array {
		return $this->rows;
	}

	public function write( int $key, array $upserts, array $deletes ): void {
		$this->upserts = $upserts;
		$this->deletes = $deletes;
	}

	public function scope( int $key ): string {
		return 'p:' . $key;
	}
}
