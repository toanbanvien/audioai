<?php

/*
 **Allow users to change page identity (Right sidebar or Fullwidth) via Theme Customizer
 */

function prespa_register_theme_identity_options( $wp_customize ) {

    // Register custom control to control logo width
    if ( method_exists( $wp_customize, 'register_control_type' ) ) {
        $wp_customize->register_control_type( 'Prespa_Range_Slider_Control' );
    }

	$wp_customize->add_setting(
		'dark_mode_logo',
		array(
			'default'           => '', // Add Default Image URL
			'sanitize_callback' => 'esc_url_raw',
		)
	);

	$wp_customize->add_control(
		new WP_Customize_Image_Control(
			$wp_customize,
			'dark_mode_logo_control',
			array(
				'label'         => __( 'Light Logo', 'prespa' ),
				'description'   => __( 'Add light version of the logo. It will be displayed in dark mode.', 'prespa' ),
				'section'       => 'title_tagline',
				'settings'      => 'dark_mode_logo',
				'button_labels' => array(
					'select' => 'Select Logo',
					'remove' => 'Remove Logo',
					'change' => 'Change Logo',
				),
			)
		)
	);

    $wp_customize->add_setting(
        'prespa_logo-width',
        array(
            'default' => '60',
            'sanitize_callback' => 'absint'
        )
    );
    
    $wp_customize->add_control(
        new Prespa_Range_Slider_Control(
            $wp_customize,
            'prespa_logo-width',
            array(
                'type' => 'prespa-range-slider',
                'label' => __( 'Logo Width', 'prespa' ),
                'section' => 'title_tagline',
                'settings' => array(
                    'desktop' => 'prespa_logo-width',
                ),
                'choices' => array(
                    'desktop' => array(
                        'min' => 40,
                        'max' => 240,
                        'step' => 5,
                        'edit' => true,
                        'unit' => 'px',
                    ),
                ),
            )
        )
    );

}

add_action( 'customize_register', 'prespa_register_theme_identity_options' );

function prespa_customize_identity_css() {
	$logo_width = get_theme_mod( 'prespa_logo-width', 60);
    ?>
<style type="text/css">
    .main-navigation-container .custom-logo-link{
        width: <?php echo esc_attr($logo_width);?>px;
    } 
</style>
    <?php
}
add_action( 'wp_head', 'prespa_customize_identity_css' );