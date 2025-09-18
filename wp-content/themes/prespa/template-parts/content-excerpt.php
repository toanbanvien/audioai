<?php
/**
 * Template part for displaying posts
 *
 * @link https://developer.wordpress.org/themes/basics/template-hierarchy/
 *
 * @package prespa
 */

?>

<article id="post-<?php the_ID(); ?>" <?php post_class(); ?> <?php prespa_schema_microdata( 'blogposts' )?>>
	<?php prespa_post_thumbnail('medium_large'); ?>
	<div class="text-wrapper">
	<?php if ( 'post' === get_post_type() ) :
		prespa_posted_in( get_theme_mod('show_post_categories', 1) );
	endif; ?>
		<header class="entry-header">
			<?php
			if ( is_singular() ) :
				the_title( '<h1 class="entry-title"' . prespa_schema_microdata( 'entry-title', 0 ) . '>' , '</h1>' );
			else :
				the_title( '<h2 class="entry-title"' . prespa_schema_microdata( 'entry-title', 0 ) . '>' . '<a href="' . esc_url( get_permalink() ) . '" rel="bookmark">', '</a></h2>' );
			endif; ?>
			<?php if ( 'post' === get_post_type() ) :
				?>
				<div class="entry-meta">
					<?php
					prespa_entry_header();
					do_action( 'prespa_header_edit_link_hook' );
					?>
				</div><!-- .entry-meta -->
			<?php endif; ?>
		</header><!-- .entry-header -->

		<p class="entry-content" <?php prespa_schema_microdata( 'entry-content' ); ?>>
			<?php prespa_the_excerpt( esc_attr( get_theme_mod( 'prespa_excerpt_length', 15 ) ) ); 

			wp_link_pages(
				array(
					'before' => '<div class="page-links">' . esc_html__( 'Pages:', 'prespa' ),
					'after'  => '</div>',
				)
			);
			?>
		</p><!-- .entry-content -->

		<footer class="entry-footer">
			<div class="entry-meta">
				<?php prespa_entry_footer(); ?>
			</div>
		</footer><!-- .entry-footer -->
	</div>
</article><!-- #post-<?php the_ID(); ?> -->
