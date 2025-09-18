<?php
/**
 * The template for displaying the footer
 *
 * Contains the closing of the #content div and all content after.
 *
 * @link https://developer.wordpress.org/themes/basics/template-files/#template-partials
 *
 * @package Prespa
 */

do_action('prespa_footer');
?>

<footer id="colophon" class="site-footer" role="contentinfo" aria-label="<?php esc_attr_e( 'Footer', 'prespa' ); ?>" <?php prespa_schema_microdata( 'footer' );?> >
	<div class="footer-content">
	<?php //Add content to the footer
	prespa_social_icons_footer();
		if ( is_active_sidebar( 'sidebar-2' ) || is_active_sidebar( 'sidebar-3' ) || is_active_sidebar( 'sidebar-4' ) || is_active_sidebar( 'sidebar-5' ) ) : ?>
			<div class="widget-area" aria-label="<?php esc_attr_e( 'Footer Widget Area', 'prespa' ); ?>">
				<?php
				if ( is_active_sidebar( 'sidebar-2' ) ) : ?>
				<div class="widget-column footer-widget-1">
					<?php dynamic_sidebar( 'sidebar-2' ); ?>
				</div>
				<?php endif;
				if ( is_active_sidebar( 'sidebar-3' ) ) : ?>
				<div class="widget-column footer-widget-2">
					<?php dynamic_sidebar( 'sidebar-3' ); ?>
				</div>
				<?php endif;
				if ( is_active_sidebar( 'sidebar-4' ) ) : ?>
				<div class="widget-column footer-widget-3">
					<?php dynamic_sidebar( 'sidebar-4' ); ?>
				</div>
				<?php endif;
				if ( is_active_sidebar( 'sidebar-5' ) ) : ?>
				<div class="widget-column footer-widget-2">
					<?php dynamic_sidebar( 'sidebar-5' ); ?>
				</div>
				<?php endif; ?>
			</div><!-- .widget-area -->
		<?php endif; ?>
		<div class="site-info">
		<?php do_action('prespa_theme_footer_credits_hook'); ?>
		</div><!-- .site-info -->
	</div>
</footer><!-- #colophon -->
</div><!-- #page -->
<?php wp_footer(); ?>
</body>
</html>