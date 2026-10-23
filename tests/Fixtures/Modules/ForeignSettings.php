<?php
/**
 * A settings provider that claims another module's key.
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Fixtures\Modules;

use Mercatus\Core\Contracts\Settings\SettingDefinition;
use Mercatus\Core\Contracts\Settings\SettingsProvider;
use Mercatus\Core\Contracts\Settings\SettingType;

/**
 * Its key belongs to pro.other, whoever uses it.
 */
final class ForeignSettings implements SettingsProvider {

	public function definitions(): array {
		return array( new SettingDefinition( 'pro.other.flag', SettingType::Boolean, false ) );
	}
}
