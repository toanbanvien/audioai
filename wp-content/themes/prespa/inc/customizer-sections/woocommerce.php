<?php
// Woocommerce colors

function prespa_customize_woocommerce_colors( $wp_customize ) {

	$wp_customize->add_section(
		'prespa_woo_colors',
		array(
			'title'       => __( 'Colors', 'prespa' ),
			'description' => __( 'Customize WooCommerce colors. By default, the theme uses theme accent colors to handle these but you can specify specific styles here for more flexibility.', 'prespa' ),
			'panel'       => 'woocommerce',
		)
	);

	$wp_customize->add_setting(
		'woo_btn_bgr_color',
		array(
			'sanitize_callback' => 'sanitize_hex_color',
		)
	);
	$wp_customize->add_control(
		new WP_Customize_Color_Control(
			$wp_customize,
			'woo_btn_bgr_color',
			array(
				'label'   => esc_html__( 'Add to Cart Background Color', 'prespa' ),
				'section' => 'prespa_woo_colors',
			)
		)
	);

	$wp_customize->add_setting(
		'woo_btn_text_color',
		array(
			'sanitize_callback' => 'sanitize_hex_color',
		)
	);
	$wp_customize->add_control(
		new WP_Customize_Color_Control(
			$wp_customize,
			'woo_btn_text_color',
			array(
				'label'   => esc_html__( 'Add to Cart Text Color', 'prespa' ),
				'section' => 'prespa_woo_colors',
			)
		)
	);

	$wp_customize->add_setting(
		'woo_info_bgr_color',
		array(
			'sanitize_callback' => 'sanitize_hex_color',
		)
	);
	$wp_customize->add_control(
		new WP_Customize_Color_Control(
			$wp_customize,
			'woo_info_bgr_color',
			array(
				'label'   => esc_html__( 'Woocommerce Notifications Background Color', 'prespa' ),
				'section' => 'prespa_woo_colors',
			)
		)
	);

}

add_action( 'customize_register', 'prespa_customize_woocommerce_colors', 10 );

function prespa_customize_woocommerce_colors_css() {

	$primary_accent_color   = prespa_customizer_values( 'primary_accent_color' );
	$secondary_accent_color = prespa_customizer_values( 'secondary_accent_color' );
	$woo_btn_text_color     = prespa_customizer_values( 'woo_btn_text_color' );
	$woo_btn_bgr_color      = prespa_customizer_values( 'woo_btn_bgr_color' );
	$woo_info_bgr_color     = get_theme_mod( 'woo_info_bgr_color' );

	?>
	
	<style>
	.woocommerce-info, .woocommerce-noreviews, .woocommerce-message, p.no-comments {
		background-color: <?php echo esc_attr( $primary_accent_color ); ?>;
	}

	.wc-block-components-product-sale-badge, 
	.woocommerce span.onsale {
		background-color: <?php echo esc_attr( $primary_accent_color ); ?>;
		border: none;
		color: #fff;
	}

	<?php if ( $woo_info_bgr_color ) : ?>
	.woocommerce-info,
	.woocommerce-noreviews,
	.woocommerce-message,
	p.no-comments {
		background-color: <?php echo esc_attr( $woo_info_bgr_color ); ?>!important;
	}
	<?php endif; ?>

	body:not(.dark-mode) .add_to_cart_button,
	body:not(.dark-mode) .single_add_to_cart_button,
	body:not(.dark-mode) button.alt.single_add_to_cart_button:hover,
	body:not(.dark-mode) .checkout-button,
	body:not(.dark-mode) .checkout-button.alt:hover,
	body:not(.dark-mode).woocommerce-page .button,
	body:not(.dark-mode).woocommerce-page #respond input#submit,
	body:not(.dark-mode).woocommerce-page #payment #place_order,
	body:not(.dark-mode) .wc-block-cart__submit-button,
	body:not(.dark-mode) .wc-block-components-checkout-place-order-button {
		<?php if ( $woo_btn_bgr_color ) : ?>
			background: <?php echo esc_attr( $woo_btn_bgr_color ); ?> !important;
		<?php else : ?>
			background: <?php echo esc_attr( $secondary_accent_color ); ?>;
		<?php endif; ?>

		<?php if ( $woo_btn_text_color ) : ?>
			color: <?php echo esc_attr( $woo_btn_text_color ); ?> !important;
		<?php else : ?>
			color: var(--wp--preset--color--text-primary);
		<?php endif; ?>
	}
	
	</style>
	
	<?php
}

add_action( 'wp_head', 'prespa_customize_woocommerce_colors_css' );
