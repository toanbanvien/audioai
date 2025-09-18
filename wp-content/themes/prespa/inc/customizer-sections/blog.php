<?php

/**
 * Register Blog Settings Section in the theme customizer.
 *
 * @package prespa
 */
function prespa_register_blog_theme_customizer( $wp_customize ) {
	$wp_customize->add_section(
		'blog_options',
		array(
			'title'       => esc_html__( 'Blog Settings', 'prespa' ),
			'description' => esc_html__( 'Customize the way blog posts are displayed.', 'prespa' ),
		)
	);

	// Post Categories
	$wp_customize->add_setting(
		'show_post_categories',
		array(
			'default'           => 1,
			'sanitize_callback' => 'prespa_sanitize_checkbox',
		)
	);
	$wp_customize->add_control(
		'show_post_categories',
		array(
			'label'       => esc_html__( 'Show post categories', 'prespa' ),
			'description' => esc_html__( 'Show post categories on top of the post', 'prespa' ),
			'section'     => 'blog_options',
			'type'        => 'checkbox',
		)
	);

	// Post thumbnail
	$wp_customize->add_setting(
		'get_post_thumbnail_from_content',
		array(
			'default'           => true,
			'sanitize_callback' => 'prespa_sanitize_checkbox',
		)
	);
	$wp_customize->add_control(
		'get_post_thumbnail_from_content',
		array(
			'label'       => esc_html__( 'Display post content image as a fallback featured image in post archives', 'prespa' ),
			'description' => esc_html__( 'If there is no featured image assigned to the post, try to get the first image from the post content.', 'prespa' ),
			'section'     => 'blog_options',
			'type'        => 'checkbox',
		)
	);

	// Post Meta Before Post Content
	$wp_customize->add_setting(
		'show_post_meta_before_post_content',
		array(
			'default'           => 'show_post_date,show_post_authors',
			'transport' 		=> 'postMessage',
			'sanitize_callback' => 'sanitize_text_field',
		)
	);
	$wp_customize->add_control(
		new Prespa_Pill_Checkbox_Custom_Control(
			$wp_customize,
			'show_post_meta_before_post_content',
			array(
				'label'       => esc_html__( 'Show Post Meta Before Post Content', 'prespa' ),
				'description' => esc_html__( 'Customize the way blog posts meta information in post archives and single blog posts are displayed. You can choose to show or hide the post meta before the post content. Drag and drop the boxes to chage the post meta order.', 'prespa' ),
				'section'     => 'blog_options',
				'input_attrs' => array(
					'sortable'  => true,
					'fullwidth' => true,
				),
				'choices'     => array(
					'show_post_date'       => esc_html__( 'Show Post Date', 'prespa' ),
					'show_post_author'     => esc_html__( 'Show Post Author', 'prespa' ),
					'show_post_categories' => esc_html__( 'Show Post Categories', 'prespa' ),
					'show_post_tags'       => esc_html__( 'Show Post Tags', 'prespa' ),
					'show_post_comments'   => esc_html__( 'Show Number of Comments', 'prespa' ),
					'show_time_to_read'    => esc_html__( 'Show Time to Read', 'prespa' )
				),
			)
		)
	);

	// Post Meta After Post Content
	$wp_customize->add_setting(
		'show_post_meta_after_post_content',
		array(
			'default'           => 'show_post_tags,show_post_comments,show_time_to_read,',
			'transport' 		=> 'postMessage',
			'sanitize_callback' => 'sanitize_text_field',
		)
	);
	$wp_customize->add_control(
		new Prespa_Pill_Checkbox_Custom_Control(
			$wp_customize,
			'show_post_meta_after_post_content',
			array(
				'label'       => esc_html__( 'Show Post Meta After Post Content', 'prespa' ),
				'description' => esc_html__( 'Customize the way blog posts meta information in post archives and single blog posts are displayed. You can choose to show or hide the post meta after the post content. Drag and drop the boxes to chage the post meta order.', 'prespa' ),
				'section'     => 'blog_options',
				'input_attrs' => array(
					'sortable'  => true,
					'fullwidth' => true,
				),
				'choices'     => array(
					'show_post_date'       => esc_html__( 'Show Post Date', 'prespa' ),
					'show_post_author'     => esc_html__( 'Show Post Author', 'prespa' ),
					'show_post_categories' => esc_html__( 'Show Post Categories', 'prespa' ),
					'show_post_tags'       => esc_html__( 'Show Post Tags', 'prespa' ),
					'show_post_comments'   => esc_html__( 'Show Number of Comments', 'prespa' ),
					'show_time_to_read'    => esc_html__( 'Show Time to Read', 'prespa' )
				),
			)
		)
	);

	// Add Settings and Controls for blog content.
	$wp_customize->add_setting(
		'post_archives_content',
		array(
			'default'           => true,
			'sanitize_callback' => 'prespa_sanitize_checkbox',
		)
	);
	$wp_customize->add_control(
		'post_archives_content',
		array(
			'label'       => esc_html__( 'Blog Excerpts', 'prespa' ),
			'description' => esc_html__( 'Show post excerpts instead of full content for your post archives.', 'prespa' ),
			'section'     => 'blog_options',
			'type'        => 'checkbox',
			'active_callback' => 'prespa_has_one_column_layout',
		)
	);
	// Add Setting and Control for Excerpt Length.
	$wp_customize->add_setting(
		'prespa_excerpt_length',
		array(
			'default'           => 15,
			'sanitize_callback' => 'absint',
		)
	);
	$wp_customize->add_control(
		'prespa_excerpt_length',
		array(
			'label'           => esc_html__( 'Post Excerpt Length', 'prespa' ),
			'section'         => 'blog_options',
			'type'            => 'number',
			'input_attrs'     => array(
				'min'  => 10,
				'max'  => 55,
				'step' => 1,
			),
			'description'     => esc_html__( 'Enter a number between 10 and 55. Default is 55.', 'prespa' ),
			'active_callback' => 'prespa_is_excerpt',
		)
	);

	$wp_customize->add_setting(
		'post_archives_columns',
		array(
			'default'           => '1',
			'sanitize_callback' => 'prespa_sanitize_select',
		)
	);
	$wp_customize->add_control(
		'post_archives_columns',
		array(
			'label'           => esc_html__( 'Post Archives Layout', 'prespa' ),
			'section'         => 'blog_options',
			'description'     => esc_html__( 'Display posts in post archives in a single or multi-column layout', 'prespa' ),
			'type'            => 'select',
			'choices'         => array(
				'1' => esc_html__( 'One Column', 'prespa' ),
				'2' => esc_html__( 'Two Columns', 'prespa' ),
				'3' => esc_html__( 'Three Columns', 'prespa' ),
				'4' => esc_html__( 'Four Columns', 'prespa' ),
			),
			'active_callback' => 'prespa_is_excerpt',
		)
	);

	$wp_customize->add_setting(
		'post_archives_display',
		array(
			'default'           => 'grid',
			'sanitize_callback' => 'prespa_sanitize_select',
		)
	);
	$wp_customize->add_control(
		'post_archives_display',
		array(
			'label'           => esc_html__( 'Post Archives Display', 'prespa' ),
			'section'         => 'blog_options',
			'description'     => esc_html__( 'Display post columns in a grid or masonry layout', 'prespa' ),
			'type'            => 'select',
			'choices'         => array(
				'grid'    => esc_html__( 'Grid', 'prespa' ),
				'masonry' => esc_html__( 'Masonry', 'prespa' ),
			),
			'active_callback' => 'prespa_has_multicolumn_layout',
		)
	);

	$wp_customize->add_setting(
		'post_loop_animation',
		array(
			'default'           => 'slide_up',
			'sanitize_callback' => 'prespa_sanitize_select',
		)
	);
	$wp_customize->add_control(
		'post_loop_animation',
		array(
			'label'       => esc_html__( 'Post Loop Animation', 'prespa' ),
			'section'     => 'blog_options',
			'description' => esc_html__( 'Animate blog posts in post archives.', 'prespa' ),
			'type'        => 'select',
			'choices'     => array(
				'zoom_in'  => esc_html__( 'Zoom In Animation', 'prespa' ),
				'slide_up' => esc_html__( 'Slide Up Animation', 'prespa' ),
				'rotate'   => esc_html__( 'Rotate Text Animation', 'prespa' ),
				'disabled' => esc_html__( 'Disabled', 'prespa' ),
			),
		)
	);

	$wp_customize->add_setting(
		'post_archives_title_hover_animation',
		array(
			'default'           => 'underline',
			'sanitize_callback' => 'prespa_sanitize_select',
		)
	);
	$wp_customize->add_control(
		'post_archives_title_hover_animation',
		array(
			'label'       => esc_html__( 'Post Archives Title Hover Animation', 'prespa' ),
			'section'     => 'blog_options',
			'description' => esc_html__( 'Choose an animation to display when hovering over a menu item. ', 'prespa' ),
			'type'        => 'select',
			'choices'     => array(
				'underline' => esc_html__( 'Underline', 'prespa' ),
				'disabled'  => esc_html__( 'Disabled', 'prespa' ),
			),
		)
	);

	$wp_customize->add_setting(
		'featured_image_display',
		array(
			'default'           => '2',
			'sanitize_callback' => 'prespa_sanitize_select',
		)
	);
	$wp_customize->add_control(
		'featured_image_display',
		array(
			'label'           => esc_html__( 'Featured Image Display', 'prespa' ),
			'section'         => 'blog_options',
			'description'     => esc_html__( 'Display the post featured image as a fullwidth header above post content or inside the post', 'prespa' ),
			'type'            => 'select',
			'choices'         => array(
				'1' => esc_html__( 'Fullwidth header', 'prespa' ),
				'2' => esc_html__( 'Inside post', 'prespa' ),
			)
		)
	);

	//Show author box
	$wp_customize->add_setting(
		'show_author_box',
		array(
			'default'           => 1,
			'sanitize_callback' => 'prespa_sanitize_checkbox',
			'transport'         => 'postMessage',
		)
	);
	$wp_customize->add_control(
		'show_author_box',
		array(
			'label'       => esc_html__( 'Show post author box', 'prespa' ),
			'description' => esc_html__( 'Display post author box after post content on single blog posts', 'prespa' ),
			'section'     => 'blog_options',
			'type'        => 'checkbox',
		)
	);

	// Related posts
	$wp_customize->add_setting(
		'show_social_share_icons',
		array(
			'default'           => 1,
			'sanitize_callback' => 'prespa_sanitize_checkbox',
			'transport'         => 'postMessage',
		)
	);
	$wp_customize->add_control(
		'show_social_share_icons',
		array(
			'label'       => esc_html__( 'Show social share icons', 'prespa' ),
			'description' => esc_html__( 'Display social share icons inside blog posts.', 'prespa' ),
			'section'     => 'blog_options',
			'type'        => 'checkbox',
		)
	);

	// Related posts
	$wp_customize->add_setting(
		'show_related_posts',
		array(
			'default'           => 1,
			'sanitize_callback' => 'prespa_sanitize_checkbox',
			'transport'         => 'postMessage',
		)
	);
	$wp_customize->add_control(
		'show_related_posts',
		array(
			'label'       => esc_html__( 'Show related posts', 'prespa' ),
			'description' => esc_html__( 'Display related posts after post content in single blog posts', 'prespa' ),
			'section'     => 'blog_options',
			'type'        => 'checkbox',
		)
	);

}

add_action( 'customize_register', 'prespa_register_blog_theme_customizer' );

function prespa_customize_blog_css() {

	$post_archives_columns = get_theme_mod( 'post_archives_columns', '1' );
	$post_animation        = get_theme_mod( 'post_loop_animation', 'slide_up' );
	$post_title_animation  = get_theme_mod( 'post_archives_title_hover_animation', 'underline' );
	?>

<style type="text/css">

	<?php
	// Post title hover animation
	if ( $post_title_animation == 'underline' ) :
		?>
	@media (min-width: 54rem) {
		.prespa-post-container .entry-title a{
			background: linear-gradient(to right, CurrentColor 0%, CurrentColor 100%);
			background-size: 0 3px;
			background-repeat: no-repeat;
			background-position: left 100%;
			display: inline;
			transition: background-size 0.6s ease-in-out;
		}

		.prespa-post-container .entry-title a:hover {
			background-size: 100% 3px;
		}
	}
	<?php endif;

	if ( ! is_single() && $post_archives_columns == 1 && prespa_is_excerpt() ) : ?>
	@media(min-width:54rem){
		.prespa-post-inner .hentry {
			display: flex;
			align-items: center;
		}

		.post-thumbnail {
			flex: 0 0 39%;
			<?php echo is_rtl() ? 'margin-left: 1rem' : 'margin-right: 1rem';?>
		}
		.post-thumbnail figure {
			margin: 0;
		}
		.post-thumbnail img {
			height: 280px;
		}
	}

	<?php elseif ( $post_archives_columns == 2 ) : ?>
	@media(min-width:54rem){
		.prespa-post-inner {
			display: grid;
			grid-template-columns: auto auto;
			grid-gap: 2rem;
		}
		<?php
		if ( ! is_single() ) : ?>
		.post-thumbnail img {
			height: 360px;
		}
		.has-sidebar .post-thumbnail img {
			height: 280px;
		}
		<?php endif; ?>
	}
	<?php elseif ( $post_archives_columns == 3 ) : ?>
	@media(min-width:54rem){
		.prespa-post-inner {
			display: grid;
			grid-template-columns: auto auto auto;
			grid-gap: 2rem;
		}
		<?php if ( ! is_single() ) : ?>
		.post-thumbnail img {
			height: 250px;
		}
		.has-sidebar .post-thumbnail img {
			height: 180px;
		}
		<?php endif; ?>
	}
	<?php elseif ( $post_archives_columns == 4 ) : ?>
	@media(min-width:54rem){
		.prespa-post-inner {
			display: grid;
			grid-template-columns: auto auto auto auto;
			grid-gap: 2rem;
		}
		<?php if ( ! is_single() ) : ?>
		.post-thumbnail img {
			height: 180px;
		}
		.has-sidebar .post-thumbnail img {
			height: 160px;
		}
		<?php endif; ?>
	}
	<?php endif;

	/* Post Loop Animation */
	if ( $post_animation !== 'disabled' ) :
		?>
	body:not(.page):not(.single):not(.woocommerce) .hentry.animated {
		opacity: 1;
		<?php echo esc_attr( $post_animation == 'slide_up' ? 'transform: translate(0, 0);' : ( $post_animation == 'zoom_in' ? 'transform: scale3d(1, 1, 1);' : 'transform: rotateX(0);' ) ); ?>
		transform: rotateX(0);
	}

	body:not(.page):not(.single):not(.woocommerce) .hentry {
		transition: all 0.5s ease-in-out;
		opacity: 0.4;
		<?php echo esc_attr( $post_animation == 'slide_up' ? 'transform: translate(0px, 3em);' : ( $post_animation == 'zoom_in' ? 'transform: scale3d(0.9, 0.9, 0.9);' : 'transform: perspective(2000px) rotateX(12deg);' ) ); ?>
	}

	<?php endif; ?>

</style>

	<?php

}
add_action( 'wp_head', 'prespa_customize_blog_css' );
