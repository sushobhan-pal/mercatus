<?php
/**
 * How the dashboard presents a module's settings (ARCH-13, VEN-16).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Contracts\Settings;

/**
 * Named by a module manifest's `settingsForm` field. The definitions stay in the module's `SettingsProvider`;
 * this adds the translated words, which Domain and Application can't hold (G-BND-2), so it lives in the API
 * layer. A setting without a label is shown under its key.
 */
interface SettingsForm {

	/**
	 * Widgets the dashboard knows beyond the one each type implies: a page picker, the permission checklist
	 * and the application-question editor.
	 */
	public const WIDGETS = array( 'page', 'permissions', 'questions', 'textarea' );

	/**
	 * The heading of the module's settings.
	 */
	public function title(): string;

	/**
	 * Labels by setting key, with optional help text and one of WIDGETS.
	 *
	 * @return array<string, array{label: string, help?: string, widget?: string}>
	 */
	public function fields(): array;
}
