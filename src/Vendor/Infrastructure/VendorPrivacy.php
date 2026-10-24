<?php
/**
 * core.vendor's personal-data exporter and eraser (VEN-9, SEC-7, TM-33).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Vendor\Infrastructure;

use Mercatus\Core\Vendor\Domain\VendorApplication;
use WP_User;

/**
 * Joins WordPress's Export and Erase Personal Data tools. The export holds every application attempt, with its
 * answers as given, and the vendor account; the eraser anonymises what nothing depends on and says what it kept
 * and why. The audit log holds no free text (SEC-7), so it has nothing to erase.
 */
final class VendorPrivacy {

	public const ID = 'mercatus-vendor';

	/**
	 * Creates the exporter and eraser.
	 *
	 * @param ApplicationStore $applications Attempts.
	 * @param VendorStore      $vendors      Vendors.
	 * @param Vendors          $commands     The erase command.
	 */
	public function __construct( private ApplicationStore $applications, private VendorStore $vendors, private Vendors $commands ) {}

	/**
	 * Adds the exporter, on `wp_privacy_personal_data_exporters`.
	 *
	 * @param array<string, array<string, mixed>> $exporters Exporters by ID.
	 * @return array<string, array<string, mixed>>
	 */
	public function exporters( array $exporters ): array {
		$exporters[ self::ID ] = array(
			'exporter_friendly_name' => __( 'Marketplace vendor', 'mercatus' ),
			'callback'               => array( $this, 'export' ),
		);

		return $exporters;
	}

	/**
	 * Adds the eraser, on `wp_privacy_personal_data_erasers`.
	 *
	 * @param array<string, array<string, mixed>> $erasers Erasers by ID.
	 * @return array<string, array<string, mixed>>
	 */
	public function erasers( array $erasers ): array {
		$erasers[ self::ID ] = array(
			'eraser_friendly_name' => __( 'Marketplace vendor', 'mercatus' ),
			'callback'             => array( $this, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * The export for an email address: one page, since a user has a handful of attempts at most.
	 *
	 * @param string $email Email address.
	 * @return array{data: list<array<string, mixed>>, done: bool}
	 */
	public function export( string $email ): array {
		$user  = get_user_by( 'email', $email );
		$items = array();
		if ( $user instanceof WP_User ) {
			foreach ( $this->applications->history( $user->ID ) as $attempt ) {
				$items[] = array(
					'group_id'          => self::ID . '-applications',
					'group_label'       => __( 'Vendor applications', 'mercatus' ),
					'group_description' => __( 'Each time you applied to sell on this marketplace, with your answers.', 'mercatus' ),
					'item_id'           => 'vendor-application-' . $attempt->id,
					'data'              => self::attempt( $attempt ),
				);
			}
			$vendor = $this->vendors->byUserId( $user->ID );
			if ( null !== $vendor ) {
				$items[] = array(
					'group_id'          => self::ID . '-account',
					'group_label'       => __( 'Vendor account', 'mercatus' ),
					'group_description' => __( 'Your account as a vendor on this marketplace.', 'mercatus' ),
					'item_id'           => 'vendor-' . $vendor->id,
					'data'              => self::pairs(
						array(
							__( 'Status', 'mercatus' ) => $vendor->status,
							__( 'Vendor since', 'mercatus' ) => VendorApplication::iso( $vendor->createdAt ),
							__( 'Reason', 'mercatus' ) => $vendor->reason,
						)
					),
				);
			}
		}

		return array(
			'data' => $items,
			'done' => true,
		);
	}

	/**
	 * Erases what it can for an email address, and says what it kept.
	 *
	 * @param string $email Email address.
	 * @return array{items_removed: bool, items_retained: bool, messages: list<string>, done: bool}
	 */
	public function erase( string $email ): array {
		$user   = get_user_by( 'email', $email );
		$result = $user instanceof WP_User ? $this->commands->erasePersonalData( $user->ID ) : array(
			'removed' => 0,
			'pending' => false,
			'vendor'  => false,
		);

		$messages = array();
		if ( $result['pending'] ) {
			$messages[] = __( 'A vendor application waiting for review was kept. Decide it, then erase again.', 'mercatus' );
		}
		if ( $result['vendor'] ) {
			$messages[] = __( 'The vendor account and its approved application were kept, because orders and payouts refer to them.', 'mercatus' );
		}

		return array(
			'items_removed'  => $result['removed'] > 0,
			'items_retained' => $result['pending'] || $result['vendor'],
			'messages'       => $messages,
			'done'           => true,
		);
	}

	/**
	 * An attempt as name and value pairs.
	 *
	 * @param VendorApplication $attempt Attempt.
	 * @return list<array{name: string, value: string}>
	 */
	private static function attempt( VendorApplication $attempt ): array {
		$fields = array(
			__( 'Attempt', 'mercatus' )    => (string) $attempt->attempt,
			__( 'Status', 'mercatus' )     => $attempt->status,
			__( 'Store name', 'mercatus' ) => $attempt->storeName,
		);
		foreach ( $attempt->answers as $answer ) {
			$fields[ $answer['label'] ] = is_bool( $answer['answer'] ) ? ( $answer['answer'] ? __( 'Yes', 'mercatus' ) : __( 'No', 'mercatus' ) ) : $answer['answer'];
		}
		$fields[ __( 'Reason', 'mercatus' ) ]    = $attempt->reason;
		$fields[ __( 'Submitted', 'mercatus' ) ] = VendorApplication::iso( $attempt->createdAt );
		$fields[ __( 'Decided', 'mercatus' ) ]   = null === $attempt->decidedAt ? null : VendorApplication::iso( $attempt->decidedAt );

		return self::pairs( $fields );
	}

	/**
	 * Name and value pairs, leaving out empty values.
	 *
	 * @param array<string, string|null> $fields Values by name.
	 * @return list<array{name: string, value: string}>
	 */
	private static function pairs( array $fields ): array {
		$pairs = array();
		foreach ( $fields as $name => $value ) {
			if ( null !== $value && '' !== $value ) {
				$pairs[] = array(
					'name'  => (string) $name,
					'value' => $value,
				);
			}
		}

		return $pairs;
	}
}
