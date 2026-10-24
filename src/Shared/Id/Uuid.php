<?php
/**
 * Time-ordered identifiers (KER-10).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Shared\Id;

use Mercatus\Core\Shared\Clock\Clock;

/**
 * UUIDv7: a millisecond timestamp followed by random bits, so new rows insert near the end of an index.
 */
final class Uuid {

	/**
	 * A new UUIDv7.
	 *
	 * @param Clock $clock Current time.
	 */
	public static function v7( Clock $clock ): string {
		$milliseconds = str_pad( dechex( (int) $clock->now()->format( 'Uv' ) ), 12, '0', STR_PAD_LEFT );
		$random       = bin2hex( random_bytes( 10 ) );
		$variant      = dechex( 0x8 | ( hexdec( $random[3] ) & 0x3 ) );

		return sprintf(
			'%s-%s-7%s-%s%s-%s',
			substr( $milliseconds, 0, 8 ),
			substr( $milliseconds, 8, 4 ),
			substr( $random, 0, 3 ),
			$variant,
			substr( $random, 4, 3 ),
			substr( $random, 7, 12 )
		);
	}
}
