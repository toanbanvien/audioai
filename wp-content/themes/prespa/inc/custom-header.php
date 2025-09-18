<?php
/**
 * Sample implementation of the Custom Header feature
 *
 * This theme does not implement Custom Header Image.
 * Instead, the theme focuses on allowing editors to create more dynamic content with Gutenberg or any other WordPress editor
 * The theme still displays a custom header with site title, description and menu.
 *
	<?php the_header_image_tag(); ?>
 *
 * @link https://developer.wordpress.org/themes/functionality/custom-headers/
 *
 * @package prespa
 */

/**
 * Set up the WordPress core custom header feature.
 *
 * @uses prespa_header_style()
 */

/* Create the header image object */
if ( ! function_exists( 'prespa_custom_header_setup' ) ) {
	function prespa_custom_header_setup() {
		add_theme_support(
			'custom-header',
			apply_filters(
				'prespa_custom_header_args',
				array(
					'default-text-color' => '333',
					'flex-width'         => true,
					'flex-height'        => true,
					'width'              => 2200,
					'wp-head-callback'   => 'prespa_header_image_css',
				)
			)
		);
	}
}
add_action( 'after_setup_theme', 'prespa_custom_header_setup' );


/* Add control to Show or hide the header image on Homepage */

function prespa_header_image_options_customize_register( $wp_customize ) {

	// Size
	$wp_customize->add_setting(
		'prespa_header_image_height',
		array(
			'default'           => 380,
			'sanitize_callback' => 'absint',
		)
	);
	$wp_customize->add_control(
		'prespa_header_image_height',
		array(
			'label'       => esc_html__( 'Header Image Height', 'prespa' ),
			'section'     => 'header_image',
			'type'        => 'number',
			'description' => esc_html__( 'Control the height of the header image. Default is 380px.', 'prespa' ),
			'input_attrs' => array(
				'min'  => 220,
				'max'  => 800,
				'step' => 1,
			),
		)
	);

		// Header Image Position
	$wp_customize->add_setting(
		'header_background_position',
		array(
			'default'           => 'center',
			'sanitize_callback' => 'prespa_sanitize_select',
			'transport'         => 'postMessage',
		)
	);

	$wp_customize->add_control(
		'header_background_position',
		array(
			'label'           => esc_html__( 'Header Background Position', 'prespa' ),
			'section'         => 'header_image',
			'description'     => esc_html__( 'Choose how you want to position the header image.', 'prespa' ),
			'type'            => 'select',
			'choices'         => array(
				'top'    => esc_html( 'top' ),
				'center' => esc_html( 'center' ),
				'bottom' => esc_html( 'bottom' ),
			),
		)
	);

	// Header Parallax Effect
	$wp_customize->add_setting(
		'header-background-attachment',
		array(
			'default'           => 1,
			'sanitize_callback' => 'prespa_sanitize_checkbox',
		)
	);

	$wp_customize->add_control(
		'header-background-attachment',
		array(
			'label'       => esc_html__( 'Header Image Parallax', 'prespa' ),
			'section'     => 'header_image',
			'description' => esc_html__( 'Add beautiful parallax effect on page scroll.', 'prespa' ),
			'type'        => 'checkbox',
		)
	);

	$wp_customize->add_setting(
		'header_gradient_density',
		array(
			'default'           => '3',
			'sanitize_callback' => 'absint',
		)
	);

	$wp_customize->add_control(
		'header_gradient_density',
		array(
			'label'           => __( 'Header Image Gradient Density', 'prespa' ),
			'description'     => __( 'Control the gradient density. From 0 to 9. Default is 4. Set it to 0 to remove the entire gradient.', 'prespa' ),
			'section'         => 'header_image',
			'type'            => 'range',
			'input_attrs'     => array(
				'min'  => 0,
				'max'  => 9,
				'step' => 1,
			),
		)
	);

	$wp_customize->add_setting(
		'prespa_gradient_color_one',
		array(
			'default'           => '#09114e',
			'sanitize_callback' => 'sanitize_hex_color',
		)
	);

	$wp_customize->add_control(
		new WP_Customize_Color_Control(
			$wp_customize,
			'prespa_gradient_color_one',
			array(
				'label'   => esc_html__( 'Header Image Gradient Color One', 'prespa' ),
				'section' => 'header_image',
			)
		)
	);

	$wp_customize->add_setting(
		'prespa_gradient_color_two',
		array(
			'default'           => '#1656d1',
			'sanitize_callback' => 'sanitize_hex_color',
		)
	);

	$wp_customize->add_control(
		new WP_Customize_Color_Control(
			$wp_customize,
			'prespa_gradient_color_two',
			array(
				'label'   => esc_html__( 'Header Image Gradient Color Two', 'prespa' ),
				'section' => 'header_image',
			)
		)
	);

	$wp_customize->add_setting(
		'prespa_header_title',
		array(
			'default'           => esc_html__( 'JOURNEY OF A THOUSAND MILES BEGINS WITH A SINGLE STEP', 'prespa' ),
			'transport'         => 'postMessage',
			'sanitize_callback' => 'sanitize_text_field',
		)
	);

	$wp_customize->add_control(
		'prespa_header_title',
		array(
			'label'           => esc_html__( 'Header Title', 'prespa' ),
			'section'         => 'header_image',
			'description'     => esc_html__( 'Change the default text of header title.', 'prespa' ),
			'type'            => 'text',
			'active_callback' => 'has_header_image',
		)
	);

	$wp_customize->add_setting(
		'prespa_header_description',
		array(
			'default'           => esc_html__( 'Don\'t let the opportunity pass by', 'prespa' ),
			'transport'         => 'postMessage',
			'sanitize_callback' => 'sanitize_text_field',
		)
	);

	$wp_customize->add_control(
		'prespa_header_description',
		array(
			'label'           => esc_html__( 'Header Description', 'prespa' ),
			'section'         => 'header_image',
			'description'     => esc_html__( 'Change the default text of the header description.', 'prespa' ),
			'type'            => 'text',
			'active_callback' => 'has_header_image',
		)
	);

	/*
	 * CALL TO ACTION
	 */

	$wp_customize->add_setting(
		'prespa_call_to_action',
		array(
			'default'           => esc_html__( 'Get Started', 'prespa' ),
			'sanitize_callback' => 'sanitize_text_field',
		)
	);

	$wp_customize->add_control(
		'prespa_call_to_action',
		array(
			'label'           => esc_html__( 'Button Text', 'prespa' ),
			'section'         => 'header_image',
			'description'     => esc_html__( 'Change the default text of the button.', 'prespa' ),
			'type'            => 'text',
			'active_callback' => 'has_header_image',
		)
	);

	// Banner Link
	$wp_customize->add_setting(
		'prespa_banner_link',
		array(
			'default'           => '#',
			'sanitize_callback' => 'esc_url_raw',
		)
	);

	$wp_customize->add_control(
		'prespa_banner_link',
		array(
			'label'           => esc_html__( 'Button Link', 'prespa' ),
			'section'         => 'header_image',
			'description'     => esc_html__( 'Add link to the button. You can link it to the About page or the Contact page or to a specific section from the Homepage.', 'prespa' ),
			'type'            => 'url',
			'active_callback' => 'has_header_image',
		)
	);

	$wp_customize->add_setting(
		'prespa_call_to_action_hover_animation',
		array(
			'default'           => 'p-btn-animation-border-move',
			'sanitize_callback' => 'prespa_sanitize_select',
		)
	);
	$wp_customize->add_control(
		'prespa_call_to_action_hover_animation',
		array(
			'label'       => esc_html__( 'Button hover animation', 'prespa' ),
			'section'     => 'header_image',
			'description' => esc_html__( 'Choose button hover animation.', 'prespa' ),
			'type'        => 'select',
			'choices'     => array(
				'p-btn-animation-rollover'    => esc_html__( 'Rollover', 'prespa' ),
				'p-btn-animation-border-move' => esc_html__( 'Border Move', 'prespa' ),
				'p-btn-animation-slide-in'    => esc_html__( 'Slide in', 'prespa' ),
				'p-btn-animation-pulse'       => esc_html__( 'Pulse', 'prespa' ),
				'p-btn-animation-shadow'      => esc_html__( 'Shadow', 'prespa' ),
			),
			'active_callback' => 'has_header_image',
		)
	);
}

add_action( 'customize_register', 'prespa_header_image_options_customize_register' );

if ( ! function_exists( 'prespa_header_style' ) ) :
	/**
	 * Styles the header image and text displayed on the blog.
	 *
	 * @see prespa_custom_header_setup().
	 */
   function prespa_header_style() {
	   $header_text_color = get_header_textcolor();

	   /*
	   * If no custom options for text are set, let's bail.
	   * get_header_textcolor() options: Any hex value, 'blank' to hide text. Default: add_theme_support( 'custom-header' ).
	   */
	   if ( get_theme_support( 'custom-header', 'default-text-color' ) === $header_text_color ) {
		   return;
	   }

	   // If we get this far, we have custom styles. Let's do this.
	   ?>
	   <style type="text/css">
		   <?php
		   // Has the text been hidden?
		   if ( ! display_header_text() ) :
		   ?>
		   .site-title,
		   .site-description {
			   position: absolute;
			   clip: rect(1px, 1px, 1px, 1px);
		   }
		   <?php else : ?>
		   .site-title a,
		   .site-description {
			   color: #<?php echo esc_attr( $header_text_color ); ?>;
		   }
		   <?php endif; ?>
	   </style>
	   <?php
   }
endif;


/* Style the custom header image */

function prespa_header_image_css() {
	$height                = get_theme_mod( 'prespa_header_image_height', 380 );
	$gradient_first_color  = get_theme_mod( 'prespa_gradient_color_one', '#09114e' );
	$gradient_second_color = get_theme_mod( 'prespa_gradient_color_two', '#1656d1' );
	$gradient_density      = get_theme_mod( 'header_gradient_density', '3' );
	$position              = get_theme_mod( 'header_background_position', 'center' );
	$attachment            = get_theme_mod( 'header-background-attachment', 1 ) ? 'fixed' : 'scroll';
	$overlay               = get_theme_mod( 'cover_template_overlay_opacity', '1' );
	$header_img_url        = has_post_thumbnail( get_the_ID() ) && ! prespa_is_blog() ? get_the_post_thumbnail_url( get_the_ID(), 'full' ) : ( ( has_header_image() && is_front_page() ) ? get_header_image() : '' );
	?>
	
<style type="text/css">

	<?php if ( $header_img_url && ( is_front_page() || is_singular() ) ) : ?>

	.header-image-wrapper {
		background-image: url(<?php echo esc_url( $header_img_url ); ?>);
		height: <?php echo esc_attr( $height ); ?>px;
		background-repeat: no-repeat;
		background-size: cover;
		background-position: <?php echo esc_attr( $position ); ?>;
		background-attachment: <?php echo esc_attr( $attachment ); ?>;
		position: relative;
		margin-bottom: 2rem;
	}

		<?php if ( $gradient_density ) : ?>
		.header-image-wrapper::before {
			background: linear-gradient(135deg, <?php echo esc_attr( prespa_hex_to_rgba( $gradient_first_color, $gradient_density / 10 ) ); ?>, <?php echo esc_attr( prespa_hex_to_rgba( $gradient_second_color, $gradient_density / 10 ) ); ?>);
			width: 100%;
			height: 100%;
			display: inline-block;
			content: "";
		}
			<?php
		endif;

	endif;
	?>

</style>
	<?php
}
