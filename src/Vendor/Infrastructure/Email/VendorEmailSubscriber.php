<?php
/**
 * Sends core.vendor's emails after commit (VEN-7, API-3).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Vendor\Infrastructure\Email;

use Mercatus\Core\Contracts\Events\EventSubscriber;
use Mercatus\Core\Shared\Events\DomainEvent;
use Mercatus\Core\Vendor\Infrastructure\ApplicationStore;
use Mercatus\Core\Vendor\Infrastructure\VendorStore;

/**
 * Runs on the critical lane right after the committing request; the poller retries a failure. Reasons come
 * from the tables, because events carry no free text.
 */
final class VendorEmailSubscriber implements EventSubscriber {

	/**
	 * Email class by event.
	 */
	private const EMAILS = array(
		'vendor.application.submitted' => ApplicationReceivedEmail::class,
		'vendor.application.approved'  => ApplicationApprovedEmail::class,
		'vendor.application.rejected'  => ApplicationRejectedEmail::class,
		'vendor.account.suspended'     => VendorSuspendedEmail::class,
		'vendor.account.reinstated'    => VendorReinstatedEmail::class,
	);

	/**
	 * Creates the subscriber.
	 *
	 * @param ApplicationStore $applications Attempts.
	 * @param VendorStore      $vendors      Vendors.
	 */
	public function __construct( private ApplicationStore $applications, private VendorStore $vendors ) {}

	public function subscribedEvents(): array {
		return array_keys( self::EMAILS );
	}

	public function handle( DomainEvent $event ): void {
		$class = self::EMAILS[ $event->name ] ?? null;
		$email = null === $class ? null : ( WC()->mailer()->get_emails()[ $class ] ?? null );
		if ( ! $email instanceof VendorEmail ) {
			return;
		}
		$user_id = is_int( $event->payload['user_id'] ?? null ) ? $event->payload['user_id'] : 0;
		$email->trigger( $user_id, $this->context( $event ) );
	}

	/**
	 * What the email shows: the store name, and the reason for a rejection or suspension.
	 *
	 * @param DomainEvent $event Event.
	 * @return array<string, mixed>
	 */
	private function context( DomainEvent $event ): array {
		$application_id = $event->payload['application_id'] ?? null;
		if ( is_int( $application_id ) ) {
			$application = $this->applications->byId( $application_id );

			return array(
				'store_name'     => null === $application ? '' : $application->storeName,
				'reason'         => $application?->reason,
				'application_id' => $application_id,
			);
		}
		$vendor_id   = $event->payload['vendor_id'] ?? null;
		$vendor      = is_int( $vendor_id ) ? $this->vendors->byId( $vendor_id ) : null;
		$application = null === $vendor ? null : $this->applications->byId( $vendor->applicationId );

		return array(
			'store_name' => null === $application ? '' : $application->storeName,
			'reason'     => $vendor?->reason,
		);
	}
}
