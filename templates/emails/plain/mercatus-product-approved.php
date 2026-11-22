<?php
/**
 * Plain-text email template (CTL-20).
 *
 * @package Mercatus\Core
 *
 * @var string      $email_heading      Heading.
 * @var string      $product_name       Product name.
 * @var string|null $reason             The moderator's reason.
 * @var string      $dashboard_url      Link to the product in the dashboard.
 * @var string      $additional_content Text the administrator added.
 */

defined( 'ABSPATH' ) || exit;

echo esc_html( wp_strip_all_tags( $email_heading ) ) . "\n\n";
/* translators: %s: product name. */ printf( esc_html__( 'Your product %s was approved.', 'mercatus' ), esc_html( (string) $product_name ) );
echo "\n\n";
echo esc_html( (string) $dashboard_url ) . "\n\n";
if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}
echo esc_html( wp_strip_all_tags( (string) apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) ) );
