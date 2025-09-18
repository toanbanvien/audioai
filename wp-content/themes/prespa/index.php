<?php
/**
 * The main template file
 *
 * This is the most generic template file in a WordPress theme
 * and one of the two required files for a theme (the other being style.css).
 * It is used to display a page when nothing more specific matches a query.
 * E.g., it puts together the home page when no home.php file exists.
 *
 * @link https://developer.wordpress.org/themes/basics/template-hierarchy/
 *
 * @package Prespa
 */

get_header();
?>
<!--Site wrapper-->
<div class="site-wrapper">
	<main id="primary" class="site-main">
		<?php
		if ( have_posts() ) :
			if ( is_home() && ! is_front_page() ) :
				?>
				<header class="entry-header">
					<h1 class="entry-title" <?php prespa_schema_microdata( 'entry-title' );?>><?php single_post_title(); ?></h1>
				</header><!-- .entry-header -->
				<?php
			endif; ?>
			<div class="prespa-post-container">
				<div class="prespa-post-inner" <?php prespa_schema_microdata( 'blog' )?>>
			<?php /* Start the Loop */
			while ( have_posts() ) :
				the_post();
				/*
				* Include the Post-Type-specific template for the content.
				* If you want to override this in a child theme, then include a file
				* called content-___.php (where ___ is the Post Type name) and that will be used instead.
				*/
				/* Display full post content or post excerpt depending on user settings in the theme customizer.*/
				if ( prespa_is_excerpt() ) :
					get_template_part( 'template-parts/content-excerpt', get_post_type() );
				else :
					get_template_part( 'template-parts/content', get_post_type() );
				endif;
			endwhile;
			echo '</div>';
			echo '</div>';
			do_action('prespa_pagination_hook');
		else :
			get_template_part( 'template-parts/content', 'none' );
		endif;
		?>
	</main><!-- #main -->
	<?php if( !prespa_is_post_archives_fullwidth() ) : get_sidebar(); endif; ?>
</div>

<?php
get_footer();
