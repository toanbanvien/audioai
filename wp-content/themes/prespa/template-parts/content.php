<?php
/**
 * Template part for displaying posts
 *
 * @link https://developer.wordpress.org/themes/basics/template-hierarchy/
 *
 * @package prespa
 */

?>

<article id="post-<?php the_ID(); ?>" <?php post_class(); ?> <?php prespa_schema_microdata( 'blogpost' )?>>
	<header class="entry-header">
		<?php
		/**
		 * prespa_breadcrumbs_hook
		 * 
		 * @since 1.0.0
		 * @hooked prespa_breadcrumbs
		 */
		if ( !has_post_thumbnail() ) {
			do_action( 'prespa_breadcrumbs_hook' );
		}
		if ( ! prespa_has_fullwidth_featured_image() ) {
			prespa_post_thumbnail();
			do_action( 'prespa_breadcrumbs_hook' );
		}

		if ( 'post' === get_post_type() ) :
			prespa_posted_in( get_theme_mod('show_post_categories', 1) ); 
		endif;
		if ( ! prespa_has_fullwidth_featured_image() ) {
			the_title( '<h1 class="entry-title"' . prespa_schema_microdata( 'entry-title', 0 ) . '>' , '</h1>' );
		}
		if ( is_singular() ) :
			if ( !has_post_thumbnail() ) :
				the_title( '<h1 class="entry-title"' . prespa_schema_microdata( 'entry-title', 0 ) . '>' , '</h1>' );
			endif;
		else :
			the_title( '<h2 class="entry-title"' . prespa_schema_microdata( 'entry-title', 0 ) . '>' . '<a href="' . esc_url( get_permalink() ) . '" rel="bookmark">', '</a></h2>' );
		endif;

		if ( 'post' === get_post_type() ) : ?>
			<div class="entry-meta">
				<?php
				prespa_entry_header();
				do_action( 'prespa_header_edit_link_hook' );
				?>
			</div><!-- .entry-meta -->
		<?php endif;
		do_action( 'prespa_social_posts_share_hook_top' ); ?>
	</header><!-- .entry-header -->

	<div class="entry-content" <?php prespa_schema_microdata( 'entry-content' ); ?>>
		<?php
		the_content(
			sprintf(
				wp_kses(
					/* translators: %s: Name of current post. Only visible to screen readers */
					__( 'Continue reading<span class="screen-reader-text"> "%s"</span>', 'prespa' ),
					array(
						'span' => array(
							'class' => array(),
						),
					)
				),
				wp_kses_post( get_the_title() )
			)
		);

		wp_link_pages(
			array(
				'before' => '<div class="page-links">' . esc_html__( 'Pages:', 'prespa' ),
				'after'  => '</div>',
			)
		);
		?>
	</div><!-- .entry-content -->

	<footer class="entry-footer">
		<?php do_action( 'prespa_social_posts_share_hook_bottom' ); ?>
		<div class="entry-meta">
			<?php prespa_entry_footer(); ?>
		</div>
	</footer><!-- .entry-footer -->
</article><!-- #post-<?php the_ID(); ?> -->
