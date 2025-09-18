<?php

function prespa_register_footer_customizer( $wp_customize ) {
	$wp_customize->add_section(
		'custom_footer',
		array(
			'title'       => __( 'Footer Options', 'prespa' ),
			'description' => __( 'Change footer styles. Add copyright info and remove default theme credits - go pro version.', 'prespa' ),
		)
	);
	/* Footer Background Color */
	$wp_customize->add_setting(
		'footer_background_color',
		array(
			'default'           => '#020205',
			'sanitize_callback' => 'sanitize_hex_color',
		)
	);
	$wp_customize->add_control(
		new WP_Customize_Color_Control(
			$wp_customize,
			'footer_background_color',
			array(
				'label'   => esc_html__( 'Footer Background Color', 'prespa' ),
				'section' => 'custom_footer',
			)
		)
	);
	// Footer text color
	$wp_customize->add_setting(
		'footer_text_color',
		array(
			'default'           => '#f5f5f5',
			'sanitize_callback' => 'sanitize_hex_color',
		)
	);
	$wp_customize->add_control(
		new WP_Customize_Color_Control(
			$wp_customize,
			'footer_text_color',
			array(
				'label'   => esc_html__( 'Footer Text Color', 'prespa' ),
				'section' => 'custom_footer',
			)
		)
	);
	/* Footer Links Color */
	$wp_customize->add_setting(
		'footer_link_color',
		array(
			'default'           => '#b7b7c7',
			'sanitize_callback' => 'sanitize_hex_color',
		)
	);
	$wp_customize->add_control(
		new WP_Customize_Color_Control(
			$wp_customize,
			'footer_link_color',
			array(
				'label'   => esc_html__( 'Footer Links Color', 'prespa' ),
				'section' => 'custom_footer',
			)
		)
	);

	$wp_customize->add_setting(
		'show_footer_social_icons',
		array(
			'default'           => true,
			'transport'         => 'postMessage',
			'sanitize_callback' => 'prespa_sanitize_checkbox',
		)
	);
	$wp_customize->add_control(
		'show_footer_social_icons',
		array(
			'label'       => esc_html__( 'Show Social icons', 'prespa' ),
			'description' => esc_html__( 'Add your social media links and their icons will automatically appear in the footer. Drag and drop the urls to rearrange the order of the icons.', 'prespa' ),
			'section'     => 'custom_footer',
			'type'        => 'checkbox',
		)
	);

	$wp_customize->add_setting(
		'prespa_social_icons_footer',
		array(
			'default'           => 'https://facebook.com/#,https://instagram.com/#,https://linkedin.com/#',
			'sanitize_callback' => 'esc_url_raw',
		)
	);

	$wp_customize->add_control(
		new Prespa_Sortable_Repeater_Custom_Control(
			$wp_customize,
			'prespa_social_icons_footer',
			array(
				'label'           => esc_html__( 'Social Icons', 'prespa' ),
				'description'     => esc_html__( 'Add your social media links and their icons will automatically appear in the top bar menu. Drag and drop the urls to rearrange the order of the icons.', 'prespa' ),
				'section'         => 'custom_footer',
				'button_labels'   => array(
					'add' => esc_html__( 'Add Row', 'prespa' ),
				),
				'active_callback' => function() {
					return get_theme_mod( 'show_footer_social_icons' );
				},
			)
		)
	);

	$wp_customize->add_setting(
		'social_url_icons_footer',
		array(
			'default'           => '',
			'sanitize_callback' => 'sanitize_text_field',
		)
	);

	$wp_customize->add_control(
		new Prespa_Single_Accordion_Custom_Control(
			$wp_customize,
			'social_url_icons_footer',
			array(
				'label'           => esc_html__( 'View list of available social icons', 'prespa' ),
				'section'         => 'custom_footer',
				'description'     => array(
					esc_html__( 'Behance', 'prespa' )    => prespa_get_svg( 'behance' ),
					esc_html__( 'Dribble', 'prespa' )    => prespa_get_svg( 'dribble' ),
					esc_html__( 'Facebook', 'prespa' )   => prespa_get_svg( 'facebook' ),
					esc_html__( 'Flickr', 'prespa' )     => prespa_get_svg( 'flickr' ),
					esc_html__( 'Github', 'prespa' )     => prespa_get_svg( 'github' ),
					esc_html__( 'Instagram', 'prespa' )  => prespa_get_svg( 'instagram' ),
					esc_html__( 'Linkedin', 'prespa' )   => prespa_get_svg( 'linkedin' ),
					esc_html__( 'Pinterest', 'prespa' )  => prespa_get_svg( 'pinterest' ),
					esc_html__( 'Snapchat', 'prespa' )   => prespa_get_svg( 'snapchat' ),
					esc_html__( 'Soundcloud', 'prespa' ) => prespa_get_svg( 'soundcloud' ),
					esc_html__( 'Telegram', 'prespa' )   => prespa_get_svg( 'telegram' ),
					esc_html__( 'Tiktok', 'prespa' )     => prespa_get_svg( 'tiktok' ),
					esc_html__( 'Tumblr', 'prespa' )     => prespa_get_svg( 'tumblr' ),
					esc_html__( 'Twitch', 'prespa' )     => prespa_get_svg( 'twitch' ),
					esc_html__( 'Twitter', 'prespa' )    => prespa_get_svg( 'twitter' ),
					esc_html__( 'Youtube', 'prespa' )    => prespa_get_svg( 'youtube' ),
					esc_html__( 'WordPress', 'prespa' )  => prespa_get_svg( 'wordpress' ),
				),
				'active_callback' => function() {
					return get_theme_mod( 'show_footer_social_icons' );
				},
			)
		)
	);

}

add_action( 'customize_register', 'prespa_register_footer_customizer' );

function prespa_footer_customize_css() {    ?>
	
<style type="text/css">
body:not(.dark-mode) .site-footer {
	background: <?php echo esc_attr( get_theme_mod( 'footer_background_color', '#020205' ) );?>;
	color: <?php echo esc_attr( get_theme_mod( 'footer_text_color', '#f5f5f5' ) );?>;
}

.site-footer h1,
.site-footer h2,
.site-footer h3,
.site-footer h4,
.site-footer h5,
.site-footer h6 {
	color: <?php echo esc_attr( get_theme_mod( 'footer_text_color', '#f5f5f5' ) );?>;
}

.site-footer a {
	color: <?php echo esc_attr( get_theme_mod( 'footer_link_color', '#b7b7c7' ) );?>;
}
</style>

	<?php
}

add_action( 'wp_footer', 'prespa_footer_customize_css' );
