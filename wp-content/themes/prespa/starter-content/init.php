<?php

if ( ! function_exists( 'prespa_starter_content_setup' ) ) :
	function prespa_starter_content_setup() {

		add_theme_support(
			'starter-content',
			array(

				'posts'     => array(
					'home'     => require __DIR__ . '/home.php',
					'about'    => require __DIR__ . '/about.php',
					'services' => require __DIR__ . '/services.php',
					'blog',
				),

				'widgets'   => array(
					'sidebar-1' => require __DIR__ . '/sidebar-1.php',
					'sidebar-2' => require __DIR__ . '/footer-1.php',
					'sidebar-3' => require __DIR__ . '/footer-2.php',
					'sidebar-4' => require __DIR__ . '/footer-3.php',
					'sidebar-5' => require __DIR__ . '/footer-4.php',
				),

				'options'   => array(
					'show_on_front'  => 'page',
					'page_on_front'  => '{{home}}',
					'page_for_posts' => '{{blog}}',
				),

				'nav_menus' => array(
					'menu-1' => array(
						'name'  => __( 'Primary', 'prespa' ),
						'items' => array(
							'page_home',
							'page_about',
							'page_service' => array(
								'type'      => 'post_type',
								'object'    => 'page',
								'object_id' => '{{services}}',
							),
							'page_blog',
						),
					),
				),
			)
		);
	}
endif;

add_action( 'after_setup_theme', 'prespa_starter_content_setup' );