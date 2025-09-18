<?php

// Header Menu Position
function prespa_register_header_customizer( $wp_customize ) {

	$wp_customize->add_panel(
		'prespa_header_options',
		array(
			'title'       => esc_html__( 'Header Options', 'prespa' ),
			'description' => esc_html__( 'Customize the site header with the options below.', 'prespa' ),
		)
	);

	$wp_customize->add_section(
		'primary_menu',
		array(
			'title'       => esc_html__( 'Site Header', 'prespa' ),
			'description' => esc_html__( 'Customize the way the site header is displayed. The site header in this theme consists of the site title, tagline and the primary menu. Enable transparent header menu - go pro version.', 'prespa' ),
			'panel'       => 'prespa_header_options',
		)
	);
	$wp_customize->add_section(
		'secondary_menu',
		array(
			'title'       => esc_html__( 'Top Bar', 'prespa' ),
			'description' => esc_html__( 'Display a top bar menu on top of the site header and customize it accordingly. You can add business info to it, social icons, e-commerce icon, etc. You can also display a standard WordPress menu here by creating a menu and linking it to the "top menu" location. (appearance => menus)', 'prespa' ),
			'panel'       => 'prespa_header_options',
		)
	);

	// Header Presets
	$wp_customize->add_setting(
		'header-menu-presets',
		array(
			'default'           => 'left',
			'sanitize_callback' => 'prespa_sanitize_select',
		)
	);
	$wp_customize->add_control(
		'header-menu-presets',
		array(
			'label'       => esc_html__( 'Header Presets', 'prespa' ),
			'section'     => 'primary_menu',
			'description' => esc_html__( ' Reorder the way header items (site title, site description, logo and primary menu) are displayed', 'prespa' ),
			'type'        => 'select',
			'choices'     => array(
				'left'   => is_rtl() ? esc_html__( 'right site title and logo', 'prespa' ) : esc_html__( 'left site title and logo', 'prespa' ),
				'center' => esc_html__( 'centered site title and logo', 'prespa' ),
				'right'  => is_rtl() ? esc_html__( 'left site title and logo', 'prespa' ) : esc_html__( 'right site title and logo', 'prespa' ),
			),
		)
	);

	$wp_customize->add_setting(
		'header-menu-position',
		array(
			'default'           => 'static',
			'sanitize_callback' => 'prespa_sanitize_select',
		)
	);
	$wp_customize->add_control(
		'header-menu-position',
		array(
			'label'       => esc_html__( 'Header Position', 'prespa' ),
			'section'     => 'primary_menu',
			'description' => esc_html__( ' Position the header on top of the page (static position), show it while scrolling (fixed) or only show it when you scroll up (sticky). The default positon is sticky. ', 'prespa' ),
			'type'        => 'select',
			'choices'     => array(
				'fixed'  => esc_html__( 'fixed', 'prespa' ),
				'sticky' => esc_html__( 'sticky', 'prespa' ),
				'static' => esc_html__( 'static', 'prespa' ),
			),
		)
	);

	$wp_customize->add_setting(
		'header-menu-alignment',
		array(
			'default'           => 'right',
			'sanitize_callback' => 'prespa_sanitize_select',
		)
	);
	$wp_customize->add_control(
		'header-menu-alignment',
		array(
			'label'           => esc_html__( 'Site Header Text Alignment', 'prespa' ),
			'section'         => 'primary_menu',
			'description'     => esc_html__( 'Position the site title and the primary menu in relation to each other.', 'prespa' ),
			'type'            => 'select',
			'choices'         => array(
				'left'   => esc_html__( 'normal', 'prespa' ),
				'center' => esc_html__( 'center', 'prespa' ),
				'right'  => esc_html__( 'space between', 'prespa' ),
			),
			'active_callback' => function() {
				return get_theme_mod( 'header-menu-presets', 'left' ) !== 'center';
			},
		)
	);

	$wp_customize->add_setting(
		'has_search',
		array(
			'default'           => 1,
			'transport'         => 'postMessage',
			'sanitize_callback' => 'prespa_sanitize_checkbox',
		)
	);

	/*
	 * CALL TO ACTION
	 */

	 $wp_customize->add_setting(
		'header_button_text',
		array(
			'default'           => esc_html__( 'Contact', 'prespa' ),
			'sanitize_callback' => 'sanitize_text_field',
		)
	);

	$wp_customize->add_control(
		'header_button_text',
		array(
			'label'           => esc_html__( 'Button Text', 'prespa' ),
			'section'     	  => 'primary_menu',
			'description'     => esc_html__( 'Change the default text of the button.', 'prespa' ),
			'type'            => 'text'
		)
	);

	// Banner Link
	$wp_customize->add_setting(
		'header_button_link',
		array(
			'default'           => '#',
			'sanitize_callback' => 'esc_url_raw',
		)
	);

	$wp_customize->add_control(
		'header_button_link',
		array(
			'label'           => esc_html__( 'Button Link', 'prespa' ),
			'section'     	  => 'primary_menu',
			'description'     => esc_html__( 'Add link to the button. You can link it to the About page or the Contact page or to a specific section from the Homepage.', 'prespa' ),
			'type'            => 'url'
		)
	);

	$wp_customize->add_control(
		'has_search',
		array(
			'label'           => esc_html__( 'Show Search Icon', 'prespa' ),
			'section'         => 'primary_menu',
			'type'            => 'checkbox',
		)
	);

	$wp_customize->add_setting(
		'has_underline',
		array(
			'default'           => 1,
			'sanitize_callback' => 'prespa_sanitize_checkbox',
		)
	);

	$wp_customize->add_control(
		'has_underline',
		array(
			'label'       => esc_html__( 'Enable Current Menu Item Underline', 'prespa' ),
			'section'     => 'primary_menu',
			'type'        => 'checkbox',
		)
	);

	$wp_customize->add_setting(
		'menu_items_animation',
		array(
			'default'           => 'underline',
			'sanitize_callback' => 'prespa_sanitize_select',
		)
	);
	$wp_customize->add_control(
		'menu_items_animation',
		array(
			'label'       => esc_html__( 'Menu Item Hover Animation', 'prespa' ),
			'section'     => 'primary_menu',
			'description' => esc_html__( 'Choose an animation to display when hovering over a menu item. ', 'prespa' ),
			'type'        => 'select',
			'choices'     => array(
				'underline'   => esc_html__( 'Underline', 'prespa' ),
				'rollover' => esc_html__( 'Rollover', 'prespa' ),
				'disabled' => esc_html__( 'Disabled', 'prespa' )
			)
		)
	);

	// Top menu
	$wp_customize->add_setting(
		'has_secondary_menu',
		array(
			'default'           => true,
			'sanitize_callback' => 'prespa_sanitize_checkbox',
		)
	);
	$wp_customize->add_control(
		'has_secondary_menu',
		array(
			'label'       => esc_html__( 'Enable Top Menu', 'prespa' ),
			'description' => esc_html__( 'Display a top bar menu on top of the site header. Please note that on mobile this menu gets combined with the primary menu for better user experience.', 'prespa' ),
			'section'     => 'secondary_menu',
			'type'        => 'checkbox',
		)
	);

	$wp_customize->add_setting(
		'location',
		array(
			'default'           => 'My Street 12',
			'transport'         => 'postMessage',
			'sanitize_callback' => 'sanitize_text_field',
		)
	);

	$wp_customize->add_control(
		'location',
		array(
			'label'           => esc_html__( 'Address', 'prespa' ),
			'section'         => 'secondary_menu',
			'type'            => 'url',
			'description'     => esc_html__( 'Add your address', 'prespa' ),
			'active_callback' => 'prespa_has_secondary_menu',
		)
	);
	$wp_customize->add_setting(
		'phone_control',
		array(
			'default'           => '00123456789',
			'transport'         => 'postMessage',
			'sanitize_callback' => 'sanitize_text_field',
		)
	);

	$wp_customize->add_control(
		'phone_control',
		array(
			'label'           => esc_html__( 'Phone number', 'prespa' ),
			'section'         => 'secondary_menu',
			'type'            => 'url',
			'description'     => esc_html__( 'Add your phone number', 'prespa' ),
			'active_callback' => 'prespa_has_secondary_menu',
		)
	);

	$wp_customize->add_setting(
		'mail_control',
		array(
			'default'           => 'email@example.com',
			'transport'         => 'postMessage',
			'sanitize_callback' => 'sanitize_text_field',
		)
	);

	$wp_customize->add_control(
		'mail_control',
		array(
			'label'           => esc_html__( 'Email address', 'prespa' ),
			'section'         => 'secondary_menu',
			'type'            => 'url',
			'description'     => esc_html__( 'Add your mail address', 'prespa' ),
			'active_callback' => 'prespa_has_secondary_menu',
		)
	);

	$wp_customize->add_setting(
		'has_wc_icons',
		array(
			'default'           => 1,
			'sanitize_callback' => 'prespa_sanitize_checkbox',
		)
	);

	$wp_customize->add_control(
		'has_wc_icons',
		array(
			'label'           => esc_html__( 'Show Woocommerce Icons', 'prespa' ),
			'description' => esc_html__( 'Unchecking this will stick the add to cart and profile icons to the right corner of the screen on page scroll.', 'prespa' ),
			'section'         => 'secondary_menu',
			'type'            => 'checkbox',
			'active_callback' => function () {
				return class_exists( 'WooCommerce' ) && prespa_has_secondary_menu();
			},
		)
	);

	$wp_customize->add_setting(
		'prespa_social_icons_setting',
		array(
			'default'           => 'https://facebook.com/#,https://instagram.com/#,https://twitter.com/#,https://linkedin.com/#',
			'sanitize_callback' => 'esc_url_raw',
		)
	);

	$wp_customize->add_control(
		new Prespa_Sortable_Repeater_Custom_Control(
			$wp_customize,
			'prespa_social_icons_setting',
			array(
				'label'           => esc_html__( 'Social Icons', 'prespa' ),
				'description'     => esc_html__( 'Add your social media links and their icons will automatically appear in the top bar menu. Drag and drop the urls to rearrange the order of the icons.', 'prespa' ),
				'section'         => 'secondary_menu',
				'button_labels'   => array(
					'add' => esc_html__( 'Add Row', 'prespa' ),
				),
				'active_callback' => 'prespa_has_secondary_menu',
			)
		)
	);

	$wp_customize->add_setting(
		'social_url_icons',
		array(
			'default'           => '',
			'sanitize_callback' => 'sanitize_text_field',
		)
	);
	$wp_customize->add_control(
		new Prespa_Single_Accordion_Custom_Control(
			$wp_customize,
			'social_url_icons',
			array(
				'label'           => esc_html__( 'View list of available social icons', 'prespa' ),
				'section'         => 'secondary_menu',
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
				'active_callback' => 'prespa_has_secondary_menu',
			)
		)
	);
}

add_action( 'customize_register', 'prespa_register_header_customizer' );

if ( ! function_exists( 'prespa_customize_header_menu_options' ) ) :
	function prespa_customize_header_menu_options() {

		$header_text_color = get_header_textcolor();

		// get menu colors
		$menu_text_color = get_theme_mod( 'prespa_header_text_color', '#000' );

		// static vs sticky header
		$header_menu_position = prespa_customizer_values( 'header-menu-position' );

		$top_menu_text_color = get_theme_mod( 'top-menu_text_color', '#334142' );
		$top_menu_bgr_color  = get_theme_mod( 'top-menu_bgr_color', 'var(--wp--preset--color--bgr)' );
		$top_bar_height      = is_admin_bar_showing() ? '2rem' : '0';

		$header_menu_alignment = get_theme_mod( 'header-menu-alignment', 'right' );
		$header_preset         = get_theme_mod( 'header-menu-presets', 'left' );

		$has_underline = get_theme_mod( 'has_underline', 1 );

		$menu_items_animation = get_theme_mod( 'menu_items_animation', 'underline' );

		?>
		<style type="text/css">
		<?php if ( $header_preset !== 'center' ) :

			if ( $header_menu_alignment == 'left' ) : ?>
				@media(min-width:54rem){
					.site-branding {
						flex-basis: content;
						max-width: 30%;
					}
					.main-navigation {
						flex: auto;
						<?php echo $header_preset == 'right' ? 'justify-content: right' : '';?>
					}
				}
			<?php elseif ( $header_menu_alignment == 'center' ) : ?>
				@media(min-width:54rem){
					.main-navigation {
						justify-content: center;
					}
					.site-branding {
						flex: auto;
						max-width: 30%;
						<?php echo $header_preset == 'right' ? 'justify-content: right' : '';?>
					}
				}
			<?php elseif ( $header_menu_alignment == 'right' ) : ?>
					@media (min-width: 54rem) {
						.main-navigation {
							<?php echo $header_preset == 'left' ? 'justify-content: right' : '';?>
						}
					}
			<?php endif;

		endif;

		if ( $header_preset == 'center' ) :
			?>
		.header-content-wrapper {
			display: block
		}
		.site-branding {
			justify-content: center;
			flex-direction: column;
			padding-top: 1rem;
		}
		.custom-logo-link {
			padding-top: 1rem;
			padding-bottom: 1rem;
			margin: auto;
		}
		.site-meta {
			text-align: center;
		}
		@media(max-width:54rem){
			.main-navigation > .menu-toggle {
				display: table;
				margin: auto;
			}
		}
		@media(min-width:54rem){
			.main-navigation, .top-menu .header-content-wrapper {
				display: flex;
				justify-content: center;
			}
		}
		<?php elseif ( $header_preset == 'right' ) : ?>
		.site-branding {
			order: 1;
		}
		.custom-logo-link {
			order: 1;
			margin-left: 1rem;
			margin-right: 0;
		}
			<?php
			if ( $header_menu_alignment !== 'center' ) :
				if ( is_rtl() ) :
					?>
				.site-branding {
					justify-content: left;
				}
				<?php else : ?>
				.site-branding {
					justify-content: right;
				}
					<?php
				endif;
			endif;
			?>
			<?php
		endif;

		if ( $header_menu_position == 'static' ) :  // static header

			?>
		.site-title a,
		.site-description {
			color: #<?php echo esc_attr( $header_text_color ); ?>;
		}
		.top-menu {
			background: <?php echo esc_attr( $top_menu_bgr_color ); ?>;
		}
		body:not(.dark-mode) .top-menu a {
			color: <?php echo esc_attr( $top_menu_text_color ); ?>;
		}
		.top-menu .feather {
			stroke: <?php echo esc_attr( $top_menu_text_color ); ?>;
		}
		.main-navigation-container {
			background-color: var(--wp--preset--color--bgr);
			position: relative;
			z-index: 9;
		}
		
		.menu-toggle .burger,
		.menu-toggle .burger:before,
		.menu-toggle .burger:after {
			border-bottom: 2px solid #333;
		}

		.main-navigation a,
		.main-navigation button {
			color: <?php echo esc_attr( $menu_text_color ); ?>;
		}
		
		<?php else : // sticky header

			?>
		.site-title a, .site-description {
			color: #<?php echo esc_attr( $header_text_color ); ?>;
		}
		.main-navigation-container {
			background: transparent;
			z-index: 1000;
		}

		.main-navigation-container.fixed-header {
			background-color: var(--wp--preset--color--bgr);
		}

		.top-menu {
			background: <?php echo esc_attr( $top_menu_bgr_color ); ?>;
		}

		body:not(.dark-mode) .top-menu a {
			color: <?php echo esc_attr( $top_menu_text_color ); ?>;
		}

		.top-menu .feather {
			stroke: <?php echo esc_attr( $top_menu_text_color ); ?>;
		}

		.fixed-header {
			top: 0;
		}

		@media (min-width: 54rem) {
			.fixed-header {
				top: <?php echo esc_attr( $top_bar_height ); ?>
			}
			.main-navigation a,
			.main-navigation button {
				color: <?php echo esc_attr( $menu_text_color ); ?>;
			}
		}

			<?php
		endif;

		// Menu Items Current Menu Item Underline
		if ( $has_underline ) : ?>
		@media (min-width: 54rem) {
			#primary-menu > .current_page_item > a > span {
				background: linear-gradient(to right, CurrentColor 0%, CurrentColor 100%);
				background-size: 100% 2px;
				background-repeat: no-repeat;
				background-position: left 100%;
			}
		}
		<?php endif;

		// Menu Items hover animation 
		if ( $menu_items_animation == 'underline' ) : ?>
		@media (min-width: 54rem) {
			#primary-menu > li > a > span{
				background: linear-gradient(to right, CurrentColor 0%, CurrentColor 100%);
				background-size: 0 2px;
				background-repeat: no-repeat;
				background-position: left 100%;
				display: inline-block;
				transition: background-size 0.6s ease-in-out;
			}

			#primary-menu > li > a:not(.wp-block-button__link) > span:hover {
				background-size: 100% 2px;
			}
		}

		<?php elseif ($menu_items_animation == 'rollover') : ?>
		@media (min-width: 54rem) {
			#primary-menu > li:not(.call-to-action) > a {
				position: relative;
				padding: 0 1rem;
				margin: 0.75rem 0;
				overflow: hidden;
				display: block;
				text-align: center;
			}
			#primary-menu > li:not(.call-to-action) > a span {
				display: block;
				transition: transform 700ms cubic-bezier(0.68, -0.55, 0.265, 1.55);
			}
			#primary-menu > li:not(.call-to-action) > a:before {
				content: attr(data-text);
				display: inline;
				position: absolute;
				transition: top 700ms cubic-bezier(0.68, -0.55, 0.265, 1.55);
				top: 100%;
				left: 0;
				right: 0;
				text-align: center;
				font-size: inherit
			}
			#primary-menu > li:not(.call-to-action) > a:hover span {
				transform: translateY(-100%);
			}
			#primary-menu > li:not(.call-to-action) > a:hover:before {
				top: 0;
			}

			#primary-menu > li.page_item_has_children > a::after, 
			#primary-menu > li.menu-item-has-children > a::after {
				position: absolute;
				<?php echo is_rtl() ? esc_attr( 'left: .35rem;' ) : esc_attr( 'right: .35rem;' ); ?>
				top: .65rem;
			}
		}

		<?php endif; ?>

		</style>
		<?php
	}
endif;

add_action( 'wp_head', 'prespa_customize_header_menu_options' );
