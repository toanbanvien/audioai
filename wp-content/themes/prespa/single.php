<?php
/**
 * The template for displaying all single posts
 *
 * @link https://developer.wordpress.org/themes/basics/template-hierarchy/#single-post
 *
 * @package Prespa
 */

get_header();
?>
<!--Site wrapper-->
<div class="site-wrapper">
	<main id="primary" class="site-main">

		<?php
		while ( have_posts() ) :
			the_post();

			get_template_part( 'template-parts/content', get_post_type() );
			
			prespa_auhor_box_markup(); //display author box
			
			prespa_the_post_navigation();

			prespa_display_related_posts(); // display related posts

			// If comments are open or we have at least one comment, load up the comment template.
			if ( comments_open() || get_comments_number() ) :
				comments_template();
			endif;

		endwhile; // End of the loop.
		?>
	</main><!-- #main -->
	<?php
	if( ! prespa_is_post_fullwidth() ) : get_sidebar(); endif; ?>
</div>

<?php get_footer();
