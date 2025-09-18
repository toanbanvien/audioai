<?php
/**
 * The sidebar containing the main widget area
 *
 * @link https://developer.wordpress.org/themes/basics/template-files/#template-partials
 *
 * @package Prespa
 */

if ( class_exists( 'Woocommerce' ) && is_active_sidebar( 'sidebar-2-1' ) && ( is_shop() || is_product_category() || is_product() ) ) {
	?>
	<aside id="secondary" class="widget-area" role="complementary" <?php prespa_schema_microdata( 'sidebar' ); ?>>
		<?php
		if ( is_active_sidebar( 'sidebar-2-1' ) ) {
				dynamic_sidebar( 'sidebar-2-1' );
		}
		?>
	</aside><!-- #secondary --> 
	<?php
} elseif ( is_active_sidebar( 'sidebar-1' ) && ! ( class_exists( 'Woocommerce' ) && is_woocommerce() ) ) {
	?>
	<aside id="secondary" class="widget-area" role="complementary" <?php prespa_schema_microdata( 'sidebar' ); ?>>
		<?php dynamic_sidebar( 'sidebar-1' ); ?>
	</aside><!-- #secondary --> 
	<?php
}
