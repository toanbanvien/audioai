<?php
/**
 * Night Mode
 *
 * @since version 1.0.0
 */

function prespa_night_mode_customizer( $wp_customize ) {
	$wp_customize->add_section(
		'night_mode',
		array(
			'title'       => esc_html( __( 'Night Mode', 'prespa' ) ),
			'description' => esc_html(
				__( 'Customize the dark theme mode. Make the dark mode default and more color options - go pro version.', 'prespa' )
			),
		)
	);

	// Enable Dark Mode
	$wp_customize->add_setting(
		'enable_dark_mode',
		array(
			'default'           => 1,
			'transport'         => 'postMessage',
			'sanitize_callback' => 'sanitize_text_field',
		)
	);

	$wp_customize->add_control(
		'enable_dark_mode',
		array(
			'label'       => esc_html__( 'Enable Dark Mode Switcher', 'prespa' ),
			'section'     => 'night_mode',
			'description' => esc_html__( 'Enable site visitors to switch to dark or light theme mode in the header menu.', 'prespa' ),
			'type'        => 'checkbox',
		)
	);

	// Change Dark Mode Colors

	$wp_customize->add_setting(
		'dark_mode_background_color',
		array(
			'default'           => '#020205',
			'sanitize_callback' => 'sanitize_hex_color',
		)
	);
	$wp_customize->add_control(
		new WP_Customize_Color_Control(
			$wp_customize,
			'dark_mode_background_color',
			array(
				'label'   => __( 'Background', 'prespa' ),
				'section' => 'night_mode',
			)
		)
	);

}

add_action( 'customize_register', 'prespa_night_mode_customizer' );

function prespa_customize_night_mode_css() {
	$secondary_accent_color = get_theme_mod( 'secondary_accent_color', '#ebeefc' );
	$isDarkMode = get_theme_mod( 'enable_dark_mode', 1 ) == 1;

	?>

<style type="text/css">
	<?php if ( $isDarkMode ) : ?>
.dark-mode, .dark-mode #search-open, .dark-mode #search-open .search-field {
	background-color: <?php echo esc_attr( get_theme_mod( 'dark_mode_background_color', '#020205' ) ); ?>;
}
.dark-mode .main-navigation-container.fixed-header, .dark-mode .top-menu {
	background-color: <?php echo esc_attr( get_theme_mod( 'dark_mode_background_color', '#020205' ) ); ?>;
}
.dark-mode .comment-form, .dark-mode .comment-body, .dark-mode .comment-form textarea {
	background-color: <?php echo esc_attr( get_theme_mod( 'dark_mode_background_color', '#020205' ) ); ?> !important;
}
.dark-mode .shopping-cart-additional-info .widget_shopping_cart {
	background-color: <?php echo esc_attr( get_theme_mod( 'dark_mode_background_color', '#020205' ) ); ?>;
}
@media (min-width: 54rem){
	.dark-mode .site-menu ul ul a {
		background-color: <?php echo esc_attr( get_theme_mod( 'dark_mode_background_color', '#020205' ) ); ?>;
	}
}
@media (max-width: 54rem){
	.dark-mode .slide-menu,
	.dark-mode .site-menu.toggled > .menu-toggle {
		background-color: <?php echo esc_attr( get_theme_mod( 'dark_mode_background_color', '#020205' ) ); ?>;
	}
}
	<?php endif; ?>
</style>

	<?php
}

add_action( 'wp_head', 'prespa_customize_night_mode_css' );
