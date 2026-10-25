<?php
/**
 * The store's fields and values, for the API layer (ARCH-2).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Store\Application;

use Mercatus\Core\Store\Domain\Store;
use Mercatus\Core\Store\Domain\StoreProfile;
use Mercatus\Core\Store\Domain\StoreSlugNormalizer;

/**
 * The domain's limits, so the routes describe exactly what the domain accepts without depending on it.
 */
final class StoreFields {

	public const CHANGEABLE = StoreProfile::FIELDS;

	public const TEXT = StoreProfile::TEXT;

	public const NAME_MAX = StoreProfile::NAME_MAX;

	public const SLUG_MIN = StoreSlugNormalizer::MIN;

	public const SLUG_MAX = StoreSlugNormalizer::MAX;

	public const VISIBILITIES = Store::VISIBILITIES;

	public const STATUSES = Store::STATUSES;
}
