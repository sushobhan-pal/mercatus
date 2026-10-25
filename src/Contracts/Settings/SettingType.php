<?php
/**
 * Kinds of value a setting holds (DRY-1).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Contracts\Settings;

/**
 * Decimal settings, such as percentages, are exact decimal strings (G-MONEY-2). Secret settings, such as a
 * provider's API key, are stored encrypted and never returned (SEC-6).
 */
enum SettingType: string {
	case Boolean = 'boolean';
	case Integer = 'integer';
	case Text    = 'string';
	case Choice  = 'choice';
	case Decimal = 'decimal';
	case Secret  = 'secret';
}
