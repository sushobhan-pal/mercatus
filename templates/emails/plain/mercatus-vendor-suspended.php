<?php
/**
 * Plain-text email template (VEN-7).
 *
 * @package Mercatus\Core
 *
 * @var string $email_heading      Heading.
 * @var string $store_name         Store name.
 * @var string $additional_content Text the administrator added.
 */

defined( 'ABSPATH' ) || exit;

echo esc_html( wp_strip_all_tags( $email_heading ) ) . "\n\n";
/* translators: %s: store name. */ printf( esc_html__( 'Your store %s is suspended.', 'mercatus' ), esc_html( (string) $store_name ) );
echo "\n\n";
/* translators: %s: reason. */ printf( esc_html__( 'Reason: %s', 'mercatus' ), esc_html( (string) $reason ) );
echo "\n\n";
if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}
echo esc_html( wp_strip_all_tags( (string) apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) ) );
