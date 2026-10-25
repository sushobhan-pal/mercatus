<?php
/**
 * Store page addresses (STO-10, STO-16).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Store\Infrastructure;

use Mercatus\Core\Contracts\Settings\SettingsReader;
use Mercatus\Core\Infrastructure\I18n\Multilingual;
use Mercatus\Core\Store\Application\StoreSettings;
use Mercatus\Core\Store\Domain\StoreSlugNormalizer;

/**
 * `/{base}/{slug}/`, with the base from `core.store.base` cleaned into a slug, and translated into the
 * visitor's language when a multilingual plugin has a translation for it.
 */
final class StoreUrls {

	/**
	 * The base's translatable string name.
	 */
	public const BASE_STRING = 'core.store.base';

	/**
	 * Bases already cleaned in this request, by the text they came from.
	 *
	 * @var array<string, string>
	 */
	private array $cleaned = array();

	/**
	 * Creates the URL builder.
	 *
	 * @param SettingsReader $settings  Settings.
	 * @param Multilingual   $languages Translations.
	 */
	public function __construct( private SettingsReader $settings, private Multilingual $languages ) {}

	/**
	 * A store page's address in the current language.
	 *
	 * @param string $slug Store slug.
	 */
	public function url( string $slug ): string {
		return home_url( user_trailingslashit( $this->base() . '/' . $slug ) );
	}

	/**
	 * The base in the current language.
	 */
	public function base(): string {
		return $this->slug( $this->languages->translate( self::BASE_STRING, $this->original() ) );
	}

	/**
	 * The base in every language, for the rewrite rules.
	 *
	 * @return list<string>
	 */
	public function bases(): array {
		return array_values( array_unique( array_map( array( $this, 'slug' ), $this->languages->translations( self::BASE_STRING, $this->original() ) ) ) );
	}

	/**
	 * Registers the base for translation (STO-16).
	 */
	public function registerStrings(): void {
		$this->languages->register( self::BASE_STRING, $this->original() );
	}

	/**
	 * The base as the administrator set it, cleaned.
	 */
	private function original(): string {
		return $this->slug( $this->settings->string( StoreSettings::BASE ) );
	}

	/**
	 * A base as a slug, `store` when nothing's left, remembered for the request because every store's address
	 * needs it.
	 *
	 * @param string $base Base.
	 */
	private function slug( string $base ): string {
		if ( ! isset( $this->cleaned[ $base ] ) ) {
			$slug                   = StoreSlugNormalizer::normalize( $base );
			$this->cleaned[ $base ] = '' === $slug ? 'store' : $slug;
		}

		return $this->cleaned[ $base ];
	}
}
