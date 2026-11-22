<?php
/**
 * Email template; copy it to yourtheme/woocommerce/emails/ to change it (CTL-20).
 *
 * @package Mercatus\Core
 *
 * @var string      $email_heading      Heading.
 * @var string      $product_name       Product name.
 * @var string|null $reason             The moderator's reason.
 * @var string      $dashboard_url      Link to the product in the dashboard.
 * @var string      $additional_content Text the administrator added.
 * @var WC_Email    $email              The email.
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email );
?>
<p><?php /* translators: %s: product name. */ printf( esc_html__( 'Your product %s was taken down. Publishing it again sends it back to review.', 'mercatus' ), esc_html( (string) $product_name ) ); ?></p>
<p><?php /* translators: %s: reason. */ printf( esc_html__( 'Reason: %s', 'mercatus' ), esc_html( (string) $reason ) ); ?></p>
<p><a href="<?php echo esc_url( (string) $dashboard_url ); ?>"><?php esc_html_e( 'Open the product in your dashboard', 'mercatus' ); ?></a></p>
<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );
