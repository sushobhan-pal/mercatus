<?php
/**
 * Email template; copy it to yourtheme/woocommerce/emails/ to change it (VEN-7).
 *
 * @package Mercatus\Core
 *
 * @var string   $email_heading      Heading.
 * @var string   $store_name         Store name.
 * @var string   $additional_content Text the administrator added.
 * @var WC_Email $email              The email.
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email );
?>
<p><?php /* translators: %s: store name. */ printf( esc_html__( 'Someone applied to sell as %s.', 'mercatus' ), esc_html( (string) $store_name ) ); ?></p>
<p><?php esc_html_e( 'Review it in the marketplace dashboard.', 'mercatus' ); ?></p>
<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );
