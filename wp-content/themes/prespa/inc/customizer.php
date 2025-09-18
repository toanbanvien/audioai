<?php
/**
 * prespa Theme Customizer
 *
 * @package prespa
 */

/**
 * Add selective refresh and postMessage support for the Theme Customizer
 */
require get_template_directory() . '/inc/customizer-selective-refresh.php';

/**
 * Call Custom Sanitization Functions.
 */
require get_template_directory() . '/inc/sanitization-functions.php';

// Helper Functions
require get_template_directory() . '/inc/customizer-helper.php';

// Customizer Controls
require get_template_directory() . '/inc/customizer-controls/class-range-control.php';
require get_template_directory() . '/inc/customizer-controls/class-sortable-repeater.php';
require get_template_directory() . '/inc/customizer-controls/class-custom-checkbox.php';
require get_template_directory() . '/inc/customizer-controls/class-single-accordion.php';
require get_template_directory() . '/inc/customizer-controls/class-tinymce-control.php';

// Customizer sections
require get_template_directory() . '/inc/customizer-sections/go-pro.php';
require get_template_directory() . '/inc/customizer-sections/site-identity.php';
require get_template_directory() . '/inc/customizer-sections/colors.php';
require get_template_directory() . '/inc/customizer-sections/header.php';
require get_template_directory() . '/inc/customizer-sections/layout.php';
require get_template_directory() . '/inc/customizer-sections/general.php';
require get_template_directory() . '/inc/customizer-sections/blog.php';
require get_template_directory() . '/inc/customizer-sections/footer.php';
require get_template_directory() . '/inc/customizer-sections/dark-mode.php';
if ( class_exists( 'WooCommerce' ) ) {
	require get_template_directory() . '/inc/customizer-sections/woocommerce.php';
}
