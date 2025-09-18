<?php
// Primary Menu hook
add_action( 'prespa_primary_menu_hook', 'prespa_primary_menu' );
// Top Menu hook
add_action( 'prespa_top_menu_hook', 'prespa_top_menu' );
// Social icons header hook
add_action( 'prespa_social_icons_hook', 'prespa_social_icons_header' );
// WC Fixed Menu Hook
if ( class_exists( 'Woocommerce' ) ) {
	add_action( 'prespa_fixed_menu_hook', 'prespa_woocommerce_my_account', 10 );
	add_action( 'prespa_fixed_menu_hook', 'prespa_woocommerce_header_cart', 20 );
}
// Header image
add_action( 'prespa_header_image_hook', 'prespa_header_image' );
// Breadcrumbs
add_action( 'prespa_breadcrumbs_hook', 'prespa_breadcrumbs' );
// Social Share Above post content
add_action( 'prespa_social_posts_share_hook_top', 'prespa_social_posts_share' );
// Social Share Below post content
add_action( 'prespa_social_posts_share_hook_bottom', 'prespa_social_posts_share' );
// Full-width header search
add_action( 'prespa_footer', 'prespa_full_screen_search', 10 );
add_action( 'prespa_footer', 'prespa_back_to_top', 20 );
// Footer credits
add_action( 'prespa_theme_footer_credits_hook', 'prespa_footer_default_theme_credits' );
// After title edit post hook
add_action( 'prespa_header_edit_link_hook', 'prespa_edit_link' );
// Pagination
add_action( 'prespa_pagination_hook', 'prespa_numeric_posts_nav' );
