<?php
/**
 * Multilingual plugins, through their documented APIs (PLAT-11, VEN-15).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Infrastructure\I18n;

/**
 * WPML through its public hooks (`wpml_object_id`, `wpml_default_language`, `wpml_register_single_string`,
 * `wpml_translate_single_string`), Polylang through its public functions (`pll_get_post`,
 * `pll_default_language`, `pll_register_string`, `pll__`). Without either, every method falls back to the
 * original, so neither is ever a dependency.
 */
final class Multilingual {

	/**
	 * The string-translation context, WPML's "domain" and Polylang's group.
	 */
	private const CONTEXT = 'Mercatus';

	/**
	 * Whether a page is another page or one of its translations.
	 *
	 * @param int $post_id     The page shown.
	 * @param int $original_id The page it might translate.
	 */
	public function isTranslationOf( int $post_id, int $original_id ): bool {
		if ( $post_id <= 0 || $original_id <= 0 ) {
			return false;
		}

		return $post_id === $original_id || $this->inDefaultLanguage( $post_id ) === $this->inDefaultLanguage( $original_id );
	}

	/**
	 * A page's translation in the current language, or the page itself.
	 *
	 * @param int $post_id Page.
	 */
	public function inCurrentLanguage( int $post_id ): int {
		$translated = (int) apply_filters( 'wpml_object_id', $post_id, 'page', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's public hook.
		$polylang   = self::polylang( 'pll_get_post', $post_id );

		return is_numeric( $polylang ) && (int) $polylang > 0 ? (int) $polylang : $translated;
	}

	/**
	 * Registers a string the administrator entered, so it can be translated.
	 *
	 * @param string $name  Unique name, for example core.vendor.question.shop_type.
	 * @param string $value The administrator's text.
	 */
	public function register( string $name, string $value ): void {
		do_action( 'wpml_register_single_string', self::CONTEXT, $name, $value ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's public hook.
		self::polylang( 'pll_register_string', $name, $value, self::CONTEXT, true );
	}

	/**
	 * A registered string in the current language.
	 *
	 * @param string $name  Its name.
	 * @param string $value The administrator's text.
	 */
	public function translate( string $name, string $value ): string {
		if ( has_filter( 'wpml_translate_single_string' ) ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's public hook.
			$translated = apply_filters( 'wpml_translate_single_string', $value, self::CONTEXT, $name ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's public hook.
		} else {
			$translated = self::polylang( 'pll__', $value );
		}

		return is_string( $translated ) && '' !== $translated ? $translated : $value;
	}

	/**
	 * A registered string in every active language, the original included, without repeats.
	 *
	 * @param string $name  Its name.
	 * @param string $value The administrator's text.
	 * @return list<string>
	 */
	public function translations( string $name, string $value ): array {
		$values    = array( $value );
		$languages = apply_filters( 'wpml_active_languages', null ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's public hook.
		foreach ( is_array( $languages ) ? array_keys( $languages ) : array() as $code ) {
			$values[] = apply_filters( 'wpml_translate_single_string', $value, self::CONTEXT, $name, (string) $code ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's public hook.
		}
		$codes = self::polylang( 'pll_languages_list' );
		foreach ( is_array( $codes ) ? $codes : array() as $code ) {
			$values[] = self::polylang( 'pll_translate_string', $value, $code );
		}

		return array_values( array_unique( array_filter( $values, static fn( mixed $text ): bool => is_string( $text ) && '' !== $text ) ) );
	}

	/**
	 * A page's translation in the site's default language, or the page itself.
	 *
	 * @param int $post_id Page.
	 */
	private function inDefaultLanguage( int $post_id ): int {
		$translated = (int) apply_filters( 'wpml_object_id', $post_id, 'page', true, apply_filters( 'wpml_default_language', null ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's public hook.
		$default    = self::polylang( 'pll_default_language' );
		$polylang   = is_string( $default ) ? self::polylang( 'pll_get_post', $post_id, $default ) : null;

		return is_numeric( $polylang ) && (int) $polylang > 0 ? (int) $polylang : $translated;
	}

	/**
	 * Calls a Polylang function when Polylang is active.
	 *
	 * @param string $function_name Function.
	 * @param mixed  ...$args       Arguments.
	 * @return mixed Its result, or null without Polylang.
	 */
	private static function polylang( string $function_name, mixed ...$args ): mixed {
		return function_exists( $function_name ) ? call_user_func_array( $function_name, $args ) : null;
	}
}
