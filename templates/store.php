<?php
/**
 * A store page in a classic theme (ARCH-9, STO-10).
 *
 * Copy it to yourtheme/mercatus/store.php to change it. The store blocks escape their own output.
 *
 * @package Mercatus\Core
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>
<main id="primary" class="site-main mercatus-store">
	<?php echo do_blocks( \Mercatus\Core\Store\Infrastructure\StorePage::CONTENT ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each block escapes its own output. ?>
</main>
<?php
get_footer();
