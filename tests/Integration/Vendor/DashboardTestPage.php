<?php
/**
 * A dashboard page for the dashboard tests.
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Integration\Vendor;

use Mercatus\Core\Contracts\Settings\SettingsReader;
use Mercatus\Core\Contracts\Settings\SettingsWriter;
use Mercatus\Core\Infrastructure\I18n\Multilingual;
use Mercatus\Core\Kernel\Kernel;
use Mercatus\Core\Vendor\Application\VendorDirectory;
use Mercatus\Core\Vendor\Application\VendorSettings;
use Mercatus\Core\Vendor\Infrastructure\DashboardPage;
use RuntimeException;

/**
 * Each test gets its own `DashboardPage`, as each request does, so one test's nonce and state never reach
 * another; the page's setting and the script it registers are put back afterwards.
 */
trait DashboardTestPage {

	/**
	 * The page under test.
	 *
	 * @var DashboardPage|null
	 */
	private ?DashboardPage $dashboard = null;

	/**
	 * Creates a page and makes it the dashboard.
	 *
	 * @return array{DashboardPage, int} The dashboard and its page's ID.
	 */
	private function dashboardPage(): array {
		$page_id = self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_title' => 'Marketplace dashboard',
			)
		);
		$this->assertIsInt( $page_id );
		$services = self::site()->container();
		$services->get( SettingsWriter::class )->set( VendorSettings::DASHBOARD_PAGE, $page_id );
		$this->dashboard = new DashboardPage( $services->get( SettingsReader::class ), $services->get( SettingsWriter::class ), new Multilingual(), $services->get( VendorDirectory::class ) );

		return array( $this->dashboard, $page_id );
	}

	/**
	 * Another page.
	 */
	private function page(): int {
		$page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$this->assertIsInt( $page_id );

		return $page_id;
	}

	/**
	 * Puts the setting and the script registry back.
	 */
	private function forgetDashboardPage(): void {
		if ( null !== $this->dashboard ) {
			remove_filter( 'wp_script_attributes', array( $this->dashboard, 'scriptAttributes' ) );
			remove_filter( 'wp_inline_script_attributes', array( $this->dashboard, 'scriptAttributes' ) );
		}
		wp_deregister_script( DashboardPage::HANDLE );
		wp_deregister_style( DashboardPage::HANDLE );
		self::site()->container()->get( SettingsWriter::class )->set( VendorSettings::DASHBOARD_PAGE, 0 );
	}

	/**
	 * The site's booted kernel.
	 *
	 * @throws RuntimeException When it didn't boot.
	 */
	private static function site(): Kernel {
		return Kernel::instance() ?? throw new RuntimeException( 'The site kernel did not boot.' );
	}
}
