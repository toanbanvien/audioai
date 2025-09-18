<?php
/**
 * Homepage sections
 *
 * @author Adore Themes
 * @package Prespa
 */

function prespa_customizer_homepage_options( $wp_customize ) {


	// Categories Section title settings.
	$wp_customize->add_setting(
		'prespa_categories_title',
		array(
			'default'           => __( 'Post Categories', 'prespa' ),
			'transport'         => 'postMessage',
			'sanitize_callback' => 'sanitize_text_field',
		)
	);

	$wp_customize->add_control(
		'prespa_categories_title',
		array(
			'label'           => esc_html__( 'Post Categories Title', 'prespa' ),
			'section'         => 'static_front_page',
			'active_callback' => 'prespa_has_categories_enabled',
		)
	);

	// Categories Section subtitle settings.
	$wp_customize->add_setting(
		'prespa_categories_subtitle',
		array(
			'default'           => '',
			'sanitize_callback' => 'sanitize_text_field',
		)
	);

	$wp_customize->add_control(
		'prespa_categories_subtitle',
		array(
			'label'           => esc_html__( 'Post Categories Subtitle', 'prespa' ),
			'section'         => 'static_front_page',
			'active_callback' => 'prespa_has_categories_enabled',
		)
	);

	// View All button label setting.
	$wp_customize->add_setting(
		'prespa_categories_view_all_button_label',
		array(
			'default'           => __( 'View All', 'prespa' ),
			'transport'         => 'postMessage',
			'sanitize_callback' => 'sanitize_text_field',
		)
	);

	$wp_customize->add_control(
		'prespa_categories_view_all_button_label',
		array(
			'label'           => esc_html__( 'View All Button Label', 'prespa' ),
			'section'         => 'static_front_page',
			'settings'        => 'prespa_categories_view_all_button_label',
			'type'            => 'text',
			'active_callback' => 'prespa_has_categories_enabled',
		)
	);

	// View All button URL setting.
	$wp_customize->add_setting(
		'prespa_categories_view_all_button_url',
		array(
			'default'           => '#',
			'sanitize_callback' => 'esc_url_raw',
		)
	);

	$wp_customize->add_control(
		'prespa_categories_view_all_button_url',
		array(
			'label'           => esc_html__( 'View All Button Link', 'prespa' ),
			'section'         => 'static_front_page',
			'settings'        => 'prespa_categories_view_all_button_url',
			'type'            => 'url',
			'active_callback' => 'prespa_has_categories_enabled',
		)
	);

	for ( $i = 1; $i <= 4; $i++ ) {

		// categories category setting.
		$wp_customize->add_setting(
			'prespa_categories_category_' . $i,
			array(
				'sanitize_callback' => 'prespa_sanitize_select',
			)
		);

		$wp_customize->add_control(
			'prespa_categories_category_' . $i,
			array(
				'label'           => sprintf( /* translators: %d: category index */ esc_html__( 'Category %d', 'prespa' ), $i ),
				'section'         => 'static_front_page',
				'settings'        => 'prespa_categories_category_' . $i,
				'type'            => 'select',
				'choices'         => prespa_get_post_cat_choices(),
				'active_callback' => 'prespa_has_categories_enabled',
			)
		);

		   // categories bg image.
		$wp_customize->add_setting(
			'prespa_categories_image_' . $i,
			array(
				'default'           => '',
				'sanitize_callback' => 'prespa_sanitize_image',
			)
		);

		$wp_customize->add_control(
			new WP_Customize_Image_Control(
				$wp_customize,
				'prespa_categories_image_' . $i,
				array(
					'label'           => sprintf( /* translators: %d: category index */ esc_html__( 'Category Image %d', 'prespa' ), $i ),
					'section'         => 'static_front_page',
					'settings'        => 'prespa_categories_image_' . $i,
					'active_callback' => 'prespa_has_categories_enabled',
				)
			)
		);

	}

	// ========== You Missed Settings ===============//

	$wp_customize->add_setting(
		'you_missed_enable',
		array(
			'default'           => true,
			'sanitize_callback' => 'prespa_sanitize_checkbox',
		)
	);

	$wp_customize->add_control(
		'you_missed_enable',
		array(
			'label'   => esc_html__( 'Show "You Missed" Section', 'prespa' ),
			'section' => 'static_front_page',
			'type'    => 'checkbox',
		)
	);

	// Title
	$wp_customize->add_setting(
		'you_missed_title',
		array(
			'default'           => esc_html__( 'You Might Have Missed', 'prespa' ),
			'sanitize_callback' => 'sanitize_text_field',
			'transport'         => 'postMessage',
		)
	);
	$wp_customize->add_control(
		'you_missed_title',
		array(
			'label'   => __( 'Title', 'prespa' ),
			'section' => 'static_front_page',
			'type'    => 'text',
		)
	);

	$wp_customize->add_setting(
		'you_missed_post_order',
		array(
			'default'           => 'desc',
			'sanitize_callback' => 'prespa_sanitize_select',
		)
	);
	$wp_customize->add_control(
		'you_missed_post_order',
		array(
			'label'       => esc_html__( 'Post Order', 'prespa' ),
			'section'     => 'static_front_page',
			'description' => esc_html__( ' Reorder the way header items (site title, site description, logo and primary menu) are displayed', 'prespa' ),
			'type'        => 'select',
			'choices'     => array(
				'desc'   => esc_html__( 'Descending', 'prespa' ),
				'asc'    => esc_html__( 'Ascending', 'prespa' ),
				'custom' => esc_html__( 'By Category', 'prespa' ),
			),
		)
	);

	$wp_customize->add_setting(
		'you_missed_post_categories',
		array(
			'sanitize_callback' => 'prespa_sanitize_select',
		)
	);

	$wp_customize->add_control(
		'you_missed_post_categories',
		array(
			'label'           => esc_html__( 'Select a category', 'prespa' ),
			'section'         => 'static_front_page',
			'type'            => 'select',
			'choices'         => prespa_get_post_cat_choices(),
			'active_callback' => function() {
				return get_theme_mod( 'you_missed_post_order', 'desc' ) == 'custom';
			},
		)
	);

}

add_action( 'customize_register', 'prespa_customizer_homepage_options' );

