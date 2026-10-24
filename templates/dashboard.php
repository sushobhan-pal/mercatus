<?php
/**
 * The marketplace dashboard's document (ARCH-13, VEN-10, VEN-15).
 *
 * Printed instead of the theme's template, with only the dashboard's own script and style and their WordPress
 * dependencies. wp_head() and wp_footer() are left out on purpose, so other plugins' scripts never load here.
 * It isn't meant to be overridden by themes.
 *
 * @package Mercatus\Core
 */

defined( 'ABSPATH' ) || exit;

?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title><?php echo esc_html( wp_get_document_title() ); ?></title>
	<?php wp_styles()->do_items( array( \Mercatus\Core\Vendor\Infrastructure\DashboardPage::HANDLE ) ); ?>
</head>
<body class="mercatus-dashboard-page">
	<div id="mercatus-dashboard"></div>
	<?php wp_scripts()->do_items( array( \Mercatus\Core\Vendor\Infrastructure\DashboardPage::HANDLE ) ); ?>
</body>
</html>
