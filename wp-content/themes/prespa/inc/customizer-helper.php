<?php
/**
 * Customizer callback functions for active_callback.
 *
 * Use these functions to show or hide customizer options.
 *
 * @package prespa
 */

 /**
  * Customizer defaults
  * These default values can be modified by a child theme
  */
if ( ! function_exists( 'prespa_customizer_values' ) ) :
	function prespa_customizer_values( $value ) {
		$defaults = array(
			'primary_accent_color'     => '#3a72d3',
			'secondary_accent_color'   => '#ebeefc',
			'body_bgr_color'           => '',
			'headings_text_color'      => '#404040',
			'link_headings_text_color' => '#404040',
			'content_layout'           => 'seperate_containers',
			'header_button_text'       => __( 'Contact', 'prespa' ),
			'has_secondary_menu'       => true,
			'header-menu-position'     => 'static',
			'woo_btn_bgr_color'        => '',
			'woo_btn_text_color'       => '',
		);

		// Return the value from the theme mod, or fallback to the default
		return get_theme_mod( $value, $defaults[ $value ] );
	}
endif;

 /**
  * Get lighter/darker color when given a hex value.
  *
  * @param string $hex Get the hex value from the customizer api.
  * @param int    $steps Steps should be between -255 and 255. Negative = darker, positive = lighter.
  * @link https://wordpress.org/themes/scaffold
  * @license GPL-2.0-or-later
  * @since 1.0.0
  */

function prespa_brightness( $hex, $steps ) {

	$steps = max( -255, min( 255, $steps ) );

	// Normalize into a six character long hex string.
	$hex = str_replace( '#', '', $hex );
	if ( strlen( $hex ) === 3 ) {
		$hex = str_repeat( substr( $hex, 0, 1 ), 2 ) . str_repeat( substr( $hex, 1, 1 ), 2 ) . str_repeat( substr( $hex, 2, 1 ), 2 );
	}

	// Split into three parts: R, G and B.
	$color_parts = str_split( $hex, 2 );
	$return      = '#';

	foreach ( $color_parts as $color ) {
		$color   = hexdec( $color ); // Convert to decimal.
		$color   = max( 0, min( 255, $color + $steps ) ); // Adjust color.
		$return .= str_pad( dechex( $color ), 2, '0', STR_PAD_LEFT ); // Make none char hex code.
	}

	return sanitize_hex_color( $return );
}

/**
 * Convert given hex values to rgb or rgba values. Useful to convert values stored in the theme customizer.
 *
 * @param string $hex Get the hex value from the customizer api.
 * @param int    $alpha Add Opacity
 * @link https://stackoverflow.com/questions/15202079/convert-hex-color-to-rgb-values-in-php
 * @since 1.1.2
 */

function prespa_hex_to_rgba( $hex, $alpha = false ) {
	$hex      = str_replace( '#', '', $hex );
	$length   = strlen( $hex );
	$rgb['r'] = hexdec( $length == 6 ? substr( $hex, 0, 2 ) : ( $length == 3 ? str_repeat( substr( $hex, 0, 1 ), 2 ) : 0 ) );
	$rgb['g'] = hexdec( $length == 6 ? substr( $hex, 2, 2 ) : ( $length == 3 ? str_repeat( substr( $hex, 1, 1 ), 2 ) : 0 ) );
	$rgb['b'] = hexdec( $length == 6 ? substr( $hex, 4, 2 ) : ( $length == 3 ? str_repeat( substr( $hex, 2, 1 ), 2 ) : 0 ) );
	if ( $alpha ) {
		$rgb['a'] = $alpha;
	}
	return implode( array_keys( $rgb ) ) . '(' . implode( ', ', $rgb ) . ')';
}

/*
 * Callback to show excerpt or full post content on archive pages
 * Based on theme customizer
 */
function prespa_is_excerpt() {
	return get_theme_mod( 'post_archives_content', true );
}

function prespa_has_breadcrumbs() {
	$post_breadcrumbs = get_theme_mod( 'show_post_breadcrumbs', 1 );
	$page_breadcrumbs = get_theme_mod( 'show_page_breadcrumbs', 1 );
	return 'post' === get_post_type() ? $post_breadcrumbs : ( 'page' === get_post_type() ? $page_breadcrumbs : 0 );
}

function prespa_has_secondary_menu() {
	return prespa_customizer_values( 'has_secondary_menu' );
}

function prespa_is_fixed_header() {
	return prespa_customizer_values( 'header-menu-position' ) == 'fixed';
}

function prespa_is_sticky_header() {
	return prespa_customizer_values( 'header-menu-position' ) == 'sticky';
}

function prespa_is_static_header() {
	return prespa_customizer_values( 'header-menu-position' ) == 'static';
}

function prespa_has_multicolumn_layout() {
	return get_theme_mod( 'post_archives_columns', '1' ) !== '1';
}

function prespa_topmenu_has_wc_items() {
	return ( get_theme_mod( 'has_wc_icons', 1 ) == 1 && class_exists( 'WooCommerce' ) );
}

function prespa_has_categories_enabled() {
	return get_theme_mod( 'prespa_categories_section_enable', true );
}

function prespa_has_one_column_layout() {
	return get_theme_mod( 'post_archives_columns', '1' ) == '1';
}

/**
 * Featured image position
 * @since version 1.6.3 the fullwidth header works only for posts and pages
 * To add support for custom post types - extend the function via a child theme
 */

 if ( ! function_exists( 'prespa_has_fullwidth_featured_image' ) ) :
	function prespa_has_fullwidth_featured_image() {
		return 'post' === get_post_type() && has_post_thumbnail() ? get_theme_mod( 'featured_image_display', '2' ) == '1' : is_singular( array( 'post', 'page' ) );
	}
endif;

/**
 * Determine if post content should be full-width layout
 */

function prespa_is_page_fullwidth() {
	return get_theme_mod( 'page_layout', 'none' ) == 'none' && 'page' == get_post_type();
}

function prespa_is_post_fullwidth() {
	return class_exists( 'WooCommerce' ) && ( is_shop() || is_product() ) ? get_theme_mod( 'shop_page_layout', 'right' ) == 'none' : get_theme_mod( 'post_layout', 'right' ) == 'none' && is_single();
}

function prespa_is_post_archives_fullwidth() {
	return get_theme_mod( 'post_layout', 'right' ) == 'none' && prespa_is_blog();
}

function prespa_is_shop_fullwidth() {
	if ( ! class_exists( 'Woocommerce' ) ) {
		return;
	}
	return get_theme_mod( 'shop_page_layout', 'right' ) == 'none' && ( is_shop() || is_product() || is_product_category() );
}
