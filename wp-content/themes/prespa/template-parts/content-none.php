<?php
/**
 * Template part for displaying a message that posts cannot be found
 *
 * @link https://developer.wordpress.org/themes/basics/template-hierarchy/
 *
 * @package prespa
 */

?>

<section class="no-results not-found">
<div class="wp-block-columns">
<div class="wp-block-column">
	<header class="page-header">
		<h1 class="page-title"><?php esc_html_e( 'Nothing Found', 'prespa' ); ?></h1>
	</header><!-- .page-header -->

	<div class="page-content">
		<?php
		if ( is_home() && current_user_can( 'publish_posts' ) ) :

			printf(
				'<p>' . wp_kses(
					/* translators: 1: link to WP admin prespa post page. */
					__( 'Ready to publish your first post? <a href="%1$s">Get started here</a>.', 'prespa' ),
					array(
						'a' => array(
							'href' => array(),
						),
					)
				) . '</p>',
				esc_url( admin_url( 'post-prespa.php' ) )
			);

		elseif ( is_search() ) :
			?>

			<p><?php esc_html_e( 'Sorry, but nothing matched your search terms. Please try again with some different keywords.', 'prespa' ); ?></p>
			<?php
			get_search_form();

		else :
			?>

			<p><?php esc_html_e( 'It seems we can&rsquo;t find what you&rsquo;re looking for. Perhaps searching can help.', 'prespa' ); ?></p>
			<?php
			get_search_form();

		endif;
		?>
	</div><!-- .page-content -->
</div>
<div class="wp-block-column">
	<img src="<?php echo esc_url(get_template_directory_uri( ) )?>/assets/img/404.png"/>
</div>
</section><!-- .no-results -->
