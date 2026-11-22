<?php
/**
 * Vendor rich text: one fixed allow-list (SEC-7, G-SEC-5, STO-5, CTL-2).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Infrastructure\Content;

/**
 * Store and product descriptions share one list: paragraphs, line breaks, emphasis, lists and links. Text is
 * cleaned on save, with links marked nofollow and ugc, and escaped again on output (TM-28).
 */
final class RichText {

	/**
	 * The allow-list.
	 */
	public const ALLOWED = array(
		'p'      => array(),
		'br'     => array(),
		'strong' => array(),
		'em'     => array(),
		'b'      => array(),
		'i'      => array(),
		'ul'     => array(),
		'ol'     => array(),
		'li'     => array(),
		'a'      => array(
			'href'  => true,
			'title' => true,
		),
	);

	/**
	 * Cleans vendor rich text on save.
	 *
	 * @param string $html Vendor text.
	 */
	public static function clean( string $html ): string {
		return wp_unslash( wp_rel_ugc( wp_slash( wp_kses( $html, self::ALLOWED ) ) ) );
	}

	/**
	 * Escapes vendor rich text on output.
	 *
	 * @param string $html Stored text.
	 */
	public static function escape( string $html ): string {
		return wp_kses( $html, self::ALLOWED );
	}
}
