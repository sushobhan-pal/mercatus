<?php
/**
 * The dashboard and its strings with a multilingual plugin (PLAT-11, VEN-15).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Integration\Vendor;

use Mercatus\Core\Vendor\Application\VendorDirectory;
use Mercatus\Core\Vendor\Application\VendorSettings;
use Mercatus\Core\Vendor\Infrastructure\Vendors;
use WP_UnitTestCase;

/**
 * With WPML's public hooks answering as WPML does, a translation of the dashboard page is still the dashboard,
 * the dashboard's address follows the visitor's language, and the administrator's questions register for
 * translation and show translated, while a choice keeps its original value.
 */
final class MultilingualDashboardPageTest extends WP_UnitTestCase {

	use DashboardTestPage;

	/**
	 * The French translation of the dashboard page.
	 *
	 * @var int
	 */
	private int $french = 0;

	/**
	 * The dashboard page.
	 *
	 * @var int
	 */
	private int $original = 0;

	public function tear_down(): void {
		remove_all_filters( 'wpml_object_id' );
		remove_all_filters( 'wpml_default_language' );
		remove_all_filters( 'wpml_translate_single_string' );
		remove_all_actions( 'wpml_register_single_string' );
		self::site()->settings()->set( VendorSettings::APPLICATION_FIELDS, '' );
		$this->forgetDashboardPage();
		parent::tear_down();
	}

	public function testATranslationOfTheDashboardPageIsTheDashboard(): void {
		list( $dashboard, $this->original ) = $this->dashboardPage();
		$this->french                       = $this->page();
		$unrelated                          = $this->page();
		add_filter( 'wpml_default_language', static fn(): string => 'en' );
		add_filter( 'wpml_object_id', array( $this, 'wpmlObjectId' ), 10, 4 );

		$this->assertTrue( $dashboard->isDashboard( $this->french ) );
		$this->assertTrue( $dashboard->isDashboard( $this->original ) );
		$this->assertFalse( $dashboard->isDashboard( $unrelated ) );

		$this->go_to( (string) get_permalink( $this->french ) );
		$dashboard->redirect();
		$this->assertStringEndsWith( '/templates/dashboard.php', $dashboard->template( '/theme/page.php' ) );
		$this->assertSame( get_permalink( $this->french ), $dashboard->url() );
	}

	public function testTheAdministratorsQuestionsAreTranslatable(): void {
		self::site()->settings()->set(
			VendorSettings::APPLICATION_FIELDS,
			(string) wp_json_encode(
				array(
					array(
						'id'      => 'shop_type',
						'label'   => 'Shop type',
						'type'    => 'choice',
						'choices' => array( 'Books', 'Maps' ),
					),
				)
			)
		);
		$registered = array();
		add_action(
			'wpml_register_single_string',
			static function ( string $context, string $name, string $value ) use ( &$registered ): void {
				$registered[ $name ] = array( $context, $value );
			},
			10,
			3
		);
		add_filter(
			'wpml_translate_single_string',
			static fn( string $value, string $context, string $name ): string => array(
				'core.vendor.question.shop_type' => 'Type de boutique',
				'Maps'                           => 'Cartes',
			)[ str_starts_with( $name, 'core.vendor.question.shop_type.choice.' ) ? $value : $name ] ?? $value,
			10,
			3
		);

		self::site()->container()->get( Vendors::class )->registerStrings();
		$form     = self::site()->container()->get( VendorDirectory::class )->registrationForm();
		$question = array_column( $form['questions'], null, 'id' )['shop_type'];

		$this->assertSame( array( 'Mercatus', 'Shop type' ), $registered['core.vendor.question.shop_type'] );
		$this->assertCount( 3, $registered, 'The label and both choices.' );
		$this->assertSame( 'Type de boutique', $question['label'] );
		$this->assertSame(
			array(
				array(
					'value' => 'Books',
					'label' => 'Books',
				),
				array(
					'value' => 'Maps',
					'label' => 'Cartes',
				),
			),
			$question['choices']
		);
	}

	/**
	 * WPML's `wpml_object_id`: the original in the default language, the translation in the current one (French).
	 *
	 * @param mixed       $element_id      Element.
	 * @param string      $type            Element type.
	 * @param bool        $return_original Return the element when it has no translation.
	 * @param string|null $language        Language; null for the current one.
	 * @return mixed
	 */
	public function wpmlObjectId( mixed $element_id, string $type, bool $return_original, ?string $language = null ): mixed {
		if ( 'en' === $language && $this->french === $element_id ) {
			return $this->original;
		}
		if ( null === $language && $this->original === $element_id ) {
			return $this->french;
		}

		return $element_id;
	}
}
