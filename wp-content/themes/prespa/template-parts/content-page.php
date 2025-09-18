<?php
/**
 * Template part for displaying page content in page.php
 *
 * @link https://developer.wordpress.org/themes/basics/template-hierarchy/
 *
 * @package prespa
 */

?>

<article id="post-<?php the_ID(); ?>" <?php post_class(); ?> <?php prespa_schema_microdata( 'page' )?>>
	<header class="entry-header">
	<?php if( ! has_post_thumbnail( ) ) :
		/**
		 * prespa_breadcrumbs_hook
		 * 
		 * @since 1.0.0
		 * @hooked prespa_breadcrumbs
		 */
		do_action( 'prespa_breadcrumbs_hook' );
		the_title( '<h1 class="entry-title"' . prespa_schema_microdata( 'entry-title', 0 ) . '>' , '</h1>' );
	endif; ?>
	</header><!-- .entry-header -->
		<div class="entry-content" <?php prespa_schema_microdata( 'entry-content' ); ?>>
			<?php
			the_content();

			wp_link_pages(
				array(
					'before' => '<div class="page-links">' . esc_html__( 'Pages:', 'prespa' ),
					'after'  => '</div>',
				)
			);
			?>
		</div><!-- .entry-content -->

	<?php if ( get_edit_post_link() ) : ?>
		<footer class="entry-footer">
			<?php
			edit_post_link(
				sprintf(
					wp_kses(
						/* translators: %s: Name of current post. Only visible to screen readers */
						__( 'Edit <span class="screen-reader-text">%s</span>', 'prespa' ),
						array(
							'span' => array(
								'class' => array(),
							),
						)
					),
					wp_kses_post( get_the_title() )
				),
				'<span class="edit-link">',
				'</span>'
			);
			?>
		</footer><!-- .entry-footer -->
	<?php endif; ?>
</article><!-- #post-<?php the_ID(); ?> -->