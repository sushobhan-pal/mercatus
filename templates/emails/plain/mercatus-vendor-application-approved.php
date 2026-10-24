<?php
/**
 * Plain-text email template (VEN-7).
 *
 * @package Mercatus\Core
 *
 * @var string $email_heading      Heading.
 * @var string $store_name         Store name.
 * @var string $dashboard_url      The marketplace dashboard.
 * @var string $additional_content Text the administrator added.
 */

defined( 'ABSPATH' ) || exit;

echo esc_html( wp_strip_all_tags( $email_heading ) ) . "\n\n";
/* translators: %s: store name. */ printf( esc_html__( 'Your application for %s is approved. You can start setting up your store.', 'mercatus' ), esc_html( (string) $store_name ) );
echo "\n\n";
/* translators: %s: dashboard address. */ printf( esc_html__( 'Open your dashboard: %s', 'mercatus' ), esc_url( (string) $dashboard_url ) );
echo "\n\n";
if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}
echo esc_html( wp_strip_all_tags( (string) apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) ) );
