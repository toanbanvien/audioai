<?php

function prespa_customize_colors( $wp_customize ) {

	$wp_customize->get_section( 'colors' )->description = esc_html__( 'Customze the colors of the light theme mode. To customize the dark theme mode, go to the Night Mode section. Colors that are selected in the Gutenberg Block editor will not be affected. To change these, edit the page via the admin dashboard.', 'prespa' );

	$wp_customize->add_setting(
		'primary_accent_color',
		array(
			'default'           => '#3a72d3',
			'sanitize_callback' => 'sanitize_hex_color',
		)
	);

	$wp_customize->add_control(
		new WP_Customize_Color_Control(
			$wp_customize,
			'primary_accent_color',
			array(
				'label'   => esc_html__( 'Primary Accent Color', 'prespa' ),
				'section' => 'colors',
			)
		)
	);

	$wp_customize->add_setting(
		'secondary_accent_color',
		array(
			'default'           => '#ebeefc',
			'sanitize_callback' => 'sanitize_hex_color',
		)
	);

	$wp_customize->add_control(
		new WP_Customize_Color_Control(
			$wp_customize,
			'secondary_accent_color',
			array(
				'label'   => esc_html__( 'Secondary Accent Color', 'prespa' ),
				'section' => 'colors',
			)
		)
	);

	// Headings Text Color
	$wp_customize->add_setting(
		'headings_text_color',
		array(
			'default'           => '#404040',
			'sanitize_callback' => 'sanitize_hex_color',
		)
	);
	$wp_customize->add_control(
		new WP_Customize_Color_Control(
			$wp_customize,
			'headings_text_color',
			array(
				'label'   => esc_html__( 'Headings Text Color', 'prespa' ),
				'section' => 'colors',
			)
		)
	);

	// Headings Text Color
	$wp_customize->add_setting(
		'link_headings_text_color',
		array(
			'default'           => '#404040',
			'sanitize_callback' => 'sanitize_hex_color',
		)
	);
	$wp_customize->add_control(
		new WP_Customize_Color_Control(
			$wp_customize,
			'link_headings_text_color',
			array(
				'label'   => esc_html__( 'Link Headings Text Color', 'prespa' ),
				'section' => 'colors',
			)
		)
	);

	$wp_customize->add_setting(
		'links_text_color',
		array(
			'sanitize_callback' => 'sanitize_hex_color',
		)
	);
	$wp_customize->add_control(
		new WP_Customize_Color_Control(
			$wp_customize,
			'links_text_color',
			array(
				'label'   => esc_html__( 'Links Text Color', 'prespa' ),
				'section' => 'colors',
			)
		)
	);

	// Body Text Color
	$wp_customize->add_setting(
		'body_text_color',
		array(
			'sanitize_callback' => 'sanitize_hex_color',
		)
	);
	$wp_customize->add_control(
		new WP_Customize_Color_Control(
			$wp_customize,
			'body_text_color',
			array(
				'label'   => esc_html__( 'Body Text Color', 'prespa' ),
				'section' => 'colors',
			)
		)
	);

	// Body Background Color
	$wp_customize->add_setting(
		'body_bgr_color',
		array(
			'sanitize_callback' => 'sanitize_hex_color',
		)
	);
	$wp_customize->add_control(
		new WP_Customize_Color_Control(
			$wp_customize,
			'body_bgr_color',
			array(
				'label'   => esc_html__( 'Body Background Color', 'prespa' ),
				'section' => 'colors',
			)
		)
	);

	$wp_customize->add_setting(
		'buttons_bgr_color',
		array(
			'sanitize_callback' => 'sanitize_hex_color',
		)
	);
	$wp_customize->add_control(
		new WP_Customize_Color_Control(
			$wp_customize,
			'buttons_bgr_color',
			array(
				'label'   => esc_html__( 'Buttons Background Color', 'prespa' ),
				'section' => 'colors',
			)
		)
	);

	// Header text color
	$wp_customize->add_setting(
		'prespa_header_text_color',
		array(
			'default'           => '#000',
			'sanitize_callback' => 'sanitize_hex_color',
		)
	);
	$wp_customize->add_control(
		new WP_Customize_Color_Control(
			$wp_customize,
			'prespa_header_text_color',
			array(
				'label'   => esc_html__( 'Primary Menu Text Color', 'prespa' ),
				'section' => 'colors',
			)
		)
	);

	// top menu background color
	$wp_customize->add_setting(
		'top-menu_bgr_color',
		array(
			'default'           => 'var(--wp--preset--color--bgr)',
			'sanitize_callback' => 'sanitize_hex_color',
		)
	);
	$wp_customize->add_control(
		new WP_Customize_Color_Control(
			$wp_customize,
			'top-menu_bgr_color',
			array(
				'label'           => esc_html__( 'Top Bar Background Color', 'prespa' ),
				'section'         => 'colors',
				'active_callback' => 'prespa_has_secondary_menu',
			)
		)
	);

	// top menu text color
	$wp_customize->add_setting(
		'top-menu_text_color',
		array(
			'default'           => '#334142',
			'sanitize_callback' => 'sanitize_hex_color',
		)
	);
	$wp_customize->add_control(
		new WP_Customize_Color_Control(
			$wp_customize,
			'top-menu_text_color',
			array(
				'label'           => esc_html__( 'Top Bar Text Color', 'prespa' ),
				'section'         => 'colors',
				'active_callback' => 'prespa_has_secondary_menu',
			)
		)
	);

}

add_action( 'customize_register', 'prespa_customize_colors', 10 );

if ( ! function_exists( 'prespa_customize_colors_css' ) ) :
	function prespa_customize_colors_css() {

		$body_text_color          = get_theme_mod( 'body_text_color' );
		$body_bgr_color           = prespa_customizer_values( 'body_bgr_color' );
		$headings_text_color      = prespa_customizer_values( 'headings_text_color' );
		$link_headings_text_color = prespa_customizer_values( 'link_headings_text_color' );
		$links_text_color         = get_theme_mod( 'links_text_color' );
		$buttons_bgr_color        = get_theme_mod( 'buttons_bgr_color' );
		$primary_accent_color     = prespa_customizer_values( 'primary_accent_color' );
		$secondary_accent_color   = prespa_customizer_values( 'secondary_accent_color' );

		?>
		
		<style>
		body:not(.dark-mode) {
		<?php if ( $links_text_color ) : ?>
		--wp--preset--color--links: <?php echo esc_attr( $links_text_color ); ?>;
		<?php endif; ?>
		<?php if ( $link_headings_text_color ) : ?>
		--wp--preset--color--link-headings: <?php echo esc_attr( $link_headings_text_color ); ?>;
		<?php endif; ?>
		<?php if ( $body_bgr_color ) : ?>
		--wp--preset--color--bgr: <?php echo esc_attr( $body_bgr_color ); ?>;
		<?php else : ?>
		--wp--preset--color--bgr: var(--wp--preset--color--white);
		<?php endif; ?>
		}

		<?php if ( $body_text_color ) : ?> 
		body {
			color: <?php echo esc_attr( $body_text_color ); ?>;
		}
		<?php endif; ?>
		<?php if ( $body_bgr_color ) : ?> 
		body {
			background-color: var(--wp--preset--color--bgr);
		}
		<?php endif; ?>
		h1, h2, h3, h4, h5, h6 {
			color: <?php echo esc_attr( $headings_text_color ); ?>;
		}

		body:not(.dark-mode) input[type="button"], 
		body:not(.dark-mode) input[type="reset"], 
		body:not(.dark-mode) [type="submit"],
		.wp-block-button > .slider-button {
			background-color: <?php echo $buttons_bgr_color ? esc_attr( $buttons_bgr_color ) : esc_attr( $primary_accent_color ); ?>;
		}

		<?php if ( $buttons_bgr_color ) : ?>
		.wp-element-button, .wp-block-button__link {
			background-color: <?php echo esc_attr( $buttons_bgr_color ); ?>;
		}
		<?php endif; ?>
		.back-to-top,
		.dark-mode .back-to-top,
		.navigation .page-numbers:hover,
		.navigation .page-numbers.current  {
			background-color: <?php echo $buttons_bgr_color ? esc_attr( prespa_brightness( $buttons_bgr_color, -50 ) ) : esc_attr( $primary_accent_color ); ?>
		}
		.fallback-svg {
			background: <?php echo esc_attr( prespa_hex_to_rgba( $primary_accent_color, .1 ) ); ?>;
		}
		.preloader .bounce1, .preloader .bounce2, .preloader .bounce3 {
			background-color: <?php echo esc_attr( prespa_brightness( $primary_accent_color, -25 ) ); // WPCS: XSS ok. ?>;
		}

		.top-meta a:nth-of-type(3n+1),
		.recent-posts-pattern .taxonomy-category a:nth-of-type(3n+1) {
			background-color:  <?php echo esc_attr( $primary_accent_color ); ?>;
			z-index: 1;
		}

		.top-meta a:nth-of-type(3n+1):hover,
		.recent-posts-pattern .taxonomy-category a:nth-of-type(3n+1):hover {
			background-color: <?php echo esc_attr( prespa_brightness( $primary_accent_color, -25 ) ); // WPCS: XSS ok. ?>;
		}

		.top-meta a:nth-of-type(3n+2) {
			background-color: <?php echo esc_attr( $secondary_accent_color ); ?>;
		}

		.top-meta a:nth-of-type(3n+2):hover {
			background-color: <?php echo esc_attr( prespa_brightness( $secondary_accent_color, -25 ) ); // WPCS: XSS ok. ?>;
		}

		.call-to-action.wp-block-button .wp-block-button__link {
			background-color: transparent;
		}

		@media(min-width:54rem){
			body:not(.dark-mode):not(.has-transparent-header) .call-to-action.wp-block-button .wp-block-button__link {
				background-color:  <?php echo esc_attr( $secondary_accent_color ); ?>;
				color: var(--wp--preset--color--links);
				font-weight: bold;
			}
		}

		body:not(.dark-mode) .call-to-action.wp-block-button .wp-block-button__link:hover {
			background-color: <?php echo esc_attr( $primary_accent_color ); ?>;
			color: var(--wp--preset--color--white);
		}

		.categories-section .category-meta {
			background-color: <?php echo esc_attr( prespa_hex_to_rgba( $primary_accent_color, .6 ) ); ?>;
			z-index: 1;
		}

		.categories-section .category-meta:hover {
			background-color: <?php echo esc_attr( prespa_hex_to_rgba( $primary_accent_color, .75 ) ); ?>;
			z-index: 1;
		}

		.section-features figure::before {
			background: <?php echo esc_attr( $primary_accent_color ); ?>;
			opacity: .85;
		}

		@media (max-width: 54em) {
			.slide-menu, .site-menu.toggled > .menu-toggle {
				background-color:  <?php echo esc_attr( $primary_accent_color ); ?>;
			}
		}

		@media (min-width:54em){
			#secondary .tagcloud a:hover {
				background-color: <?php echo esc_attr( $secondary_accent_color ); ?>;
			}
		}
		</style>
		
		<?php
	}

endif;

add_action( 'wp_head', 'prespa_customize_colors_css' );
