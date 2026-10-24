<?php
/**
 * A validated list request (API-1, SEC-3, IDN-7).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Shared\Http;

/**
 * Every part comes from the list's allow-list, so repositories can build their query from it directly.
 */
final readonly class ListRequest {

	/**
	 * Creates the request.
	 *
	 * @param string                $sort       Sort column, from the allow-list.
	 * @param string                $direction  asc or desc.
	 * @param int                   $perPage    Items per page.
	 * @param int|string|null       $afterValue Sort value of the last item seen; null for the first page.
	 * @param int|null              $afterId    ID of the last item seen.
	 * @param array<string, string> $filters    Filter values by field, from the allow-list.
	 */
	public function __construct(
		public string $sort,
		public string $direction,
		public int $perPage,
		public int|string|null $afterValue = null,
		public ?int $afterId = null,
		public array $filters = array(),
	) {}
}
