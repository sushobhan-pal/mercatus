<?php
/**
 * core.vendor's emails, through WooCommerce (ARCH-12, VEN-7).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Vendor\Infrastructure\Email;

use WC_Email;

/**
 * Each one appears in WooCommerce's email settings, where administrators change its text or switch it off.
 * Customer emails go out in the recipient's language (PLAT-11).
 */
abstract class VendorEmail extends WC_Email {

	/**
	 * Values the template shows.
	 *
	 * @var array<string, mixed>
	 */
	protected array $context = array();

	/**
	 * Sets up the email.
	 *
	 * @param string $id       Email ID, under mercatus_.
	 * @param string $title    Title in the email settings.
	 * @param string $about    Description in the email settings.
	 * @param bool   $customer Whether it goes to the applicant or vendor rather than the administrator.
	 */
	public function __construct( string $id, string $title, string $about, bool $customer ) {
		$this->id             = $id;
		$this->title          = $title;
		$this->description    = $about;
		$this->customer_email = $customer;
		$this->template_base  = dirname( __DIR__, 4 ) . '/templates/';
		$this->template_html  = 'emails/' . str_replace( '_', '-', $id ) . '.php';
		$this->template_plain = 'emails/plain/' . str_replace( '_', '-', $id ) . '.php';
		$this->placeholders   = array( '{store_name}' => '' );
		parent::__construct();
		if ( ! $customer ) {
			$this->recipient = $this->get_option( 'recipient', get_option( 'admin_email' ) );
		}
	}

	/**
	 * Sends the email about a user.
	 *
	 * @param int                  $user_id The applicant or vendor.
	 * @param array<string, mixed> $context Values the template shows; store_name fills {store_name}.
	 */
	public function trigger( int $user_id, array $context ): void {
		$user = get_userdata( $user_id );
		if ( false === $user ) {
			return;
		}
		$this->context                      = $context + array( 'user' => $user );
		$this->placeholders['{store_name}'] = is_string( $context['store_name'] ?? null ) ? $context['store_name'] : '';
		if ( $this->customer_email ) {
			$this->recipient = $user->user_email;
		}
		if ( ! $this->is_enabled() || '' === $this->get_recipient() ) {
			return;
		}

		$switched = $this->customer_email && switch_to_user_locale( $user_id );
		$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		if ( $switched ) {
			restore_previous_locale();
		}
	}

	public function get_content_html() {
		return wc_get_template_html( $this->template_html, $this->args( false ), '', $this->template_base );
	}

	public function get_content_plain() {
		return wc_get_template_html( $this->template_plain, $this->args( true ), '', $this->template_base );
	}

	/**
	 * The template's variables.
	 *
	 * @param bool $plain Whether it's the plain-text template.
	 * @return array<string, mixed>
	 */
	private function args( bool $plain ): array {
		return $this->context + array(
			'email_heading'      => $this->get_heading(),
			'additional_content' => $this->get_additional_content(),
			'sent_to_admin'      => ! $this->customer_email,
			'plain_text'         => $plain,
			'email'              => $this,
		);
	}
}
