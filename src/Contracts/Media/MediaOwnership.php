<?php
/**
 * Whether an attachment is a vendor's own media (SEC-7, STO-12, CTL-14).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Contracts\Media;

/**
 * core.catalog keeps media ownership; other modules, such as core.store for a store's logo and banner, check
 * an attachment through this contract instead of depending on core.catalog.
 */
interface MediaOwnership {

	/**
	 * Whether the vendor uploaded the attachment.
	 *
	 * @param int $vendor_id     Vendor.
	 * @param int $attachment_id Attachment.
	 */
	public function owns( int $vendor_id, int $attachment_id ): bool;
}
