<?php
/**
 * The template for displaying 404 pages (not found)
 *
 * @link https://codex.wordpress.org/Creating_an_Error_404_Page
 *
 * @package Prespa
 */

get_header();
?>
<!--Site wrapper-->
<div class="site-wrapper">
	<main id="primary" class="site-main">
		<section class="error-404 not-found">
			<div class="wp-block-columns">
				<div class="wp-block-column">
					<header class="page-header">
						<h1 class="page-title"><?php esc_html_e( 'Page not Found', 'prespa' ); ?></h1>
					</header><!-- .page-header -->

					<div class="page-content">
						<p><?php esc_html_e( 'The requested url was not found on this server. Maybe try one of the links below or a search?', 'prespa' ); ?></p>

							<?php
							get_search_form(); ?>

					</div><!-- .page-content -->
				</div>
				<div class="wp-block-column">
					<img src="<?php echo esc_url(get_template_directory_uri( ) )?>/assets/img/404.png"/>
				</div>
			</div>
		</section><!-- .error-404 -->

	</main><!-- #main -->
</div>

<?php get_footer();