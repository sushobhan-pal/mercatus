<?php
/**
 * Settings of the probe module used by kernel tests.
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Fixtures\Modules;

use Mercatus\Core\Contracts\Settings\SettingDefinition;
use Mercatus\Core\Contracts\Settings\SettingsProvider;
use Mercatus\Core\Contracts\Settings\SettingType;

/**
 * One Text setting for pro.probe.
 */
final class ProbeSettings implements SettingsProvider {

	public function definitions(): array {
		return array( new SettingDefinition( 'pro.probe.greeting', SettingType::Text, 'hello' ) );
	}
}
