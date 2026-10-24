<?php
/**
 * core.vendor's slice of the scale data set (core.vendor §9, PLAT-10, KER-12).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

use Mercatus\Core\Contracts\Settings\SettingsReader;
use Mercatus\Core\Contracts\Settings\SettingsWriter;
use Mercatus\Core\Database\Seeders\SeedContext;
use Mercatus\Core\Database\Seeders\SeedStep;
use Mercatus\Core\Kernel\Kernel;
use Mercatus\Core\Vendor\Application\VendorDirectory;
use Mercatus\Core\Vendor\Application\VendorSettings;

/**
 * The PLAT-10 vendors, each a customer who applied through the registration command and was approved
 * automatically, so every row, event and audit entry is the one a real approval writes. Store names and phone
 * numbers come from the step's own random sequence. Emails are switched off and password hashing is cheap
 * while it runs; the site is never signed in to.
 */
return new class() implements SeedStep {

	private const ADJECTIVES = array( 'Rare', 'Corner', 'Golden', 'Quiet', 'Northern', 'Little', 'Urban', 'Wild' );

	private const GOODS = array( 'Maps', 'Books', 'Prints', 'Ceramics', 'Textiles', 'Tea', 'Records', 'Tools' );

	public function run( SeedContext $context ): void {
		$services = ( Kernel::instance() ?? throw new RuntimeException( 'The site kernel did not boot.' ) )->container();
		$vendors  = $services->get( VendorDirectory::class );
		$settings = $services->get( SettingsWriter::class );
		$approve  = $services->get( SettingsReader::class )->bool( VendorSettings::AUTO_APPROVE );
		$cheap    = static fn( array $options ): array => array( 'cost' => 4 ) + $options;

		add_filter( 'pre_wp_mail', '__return_false' );
		add_filter( 'wp_hash_password_options', $cheap );
		$settings->set( VendorSettings::AUTO_APPROVE, true );
		try {
			$context->inBatches(
				$context->count( 'vendors' ),
				100,
				function ( int $offset, int $size ) use ( $context, $vendors ): void {
					for ( $n = $offset + 1; $n <= $offset + $size; $n++ ) {
						$this->vendor( $context, $vendors, $n );
					}
				}
			);
		} finally {
			wp_set_current_user( 0 );
			$settings->set( VendorSettings::AUTO_APPROVE, $approve );
			remove_filter( 'wp_hash_password_options', $cheap );
			remove_filter( 'pre_wp_mail', '__return_false' );
		}
	}

	/**
	 * Creates one customer and applies as them.
	 *
	 * @param SeedContext     $context Randomness.
	 * @param VendorDirectory $vendors The registration command.
	 * @param int             $n       Vendor number, from 1.
	 * @throws RuntimeException When WordPress refuses the user.
	 */
	private function vendor( SeedContext $context, VendorDirectory $vendors, int $n ): void {
		$user_id = wp_insert_user(
			array(
				'user_login'   => sprintf( 'seed-vendor-%04d', $n ),
				'user_email'   => sprintf( 'seed-vendor-%04d@example.test', $n ),
				'user_pass'    => 'seed-vendor',
				'display_name' => sprintf( 'Seed vendor %04d', $n ),
				'role'         => 'customer',
			)
		);
		if ( is_wp_error( $user_id ) ) {
			throw new RuntimeException( esc_html( $user_id->get_error_message() ) );
		}
		wp_set_current_user( $user_id );
		$vendors->register(
			array(
				'store_name' => sprintf( '%s %s %04d', self::ADJECTIVES[ $context->random->getInt( 0, count( self::ADJECTIVES ) - 1 ) ], self::GOODS[ $context->random->getInt( 0, count( self::GOODS ) - 1 ) ], $n ),
				'answers'    => array(
					'phone'   => sprintf( '+1 555 %04d', $context->random->getInt( 0, 9999 ) ),
					'message' => 'Seeded for the scale suite.',
				),
			)
		);
	}
};
