<?php
/**
 * Sidebar 3 starter content.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

$default_content = sprintf(
    '
    <!-- wp:group {"layout":{"type":"constrained"}} -->
    <div class="wp-block-group"><!-- wp:heading {"level":4} -->
    <h4 class="wp-block-heading">%1$s</h4>
    <!-- /wp:heading -->
    
    <!-- wp:paragraph -->
    <p><a href="#">%2$s</a></p>
    <!-- /wp:paragraph -->
    
    <!-- wp:paragraph -->
    <p><a href="#">%3$s</a></p>
    <!-- /wp:paragraph -->
    
    <!-- wp:paragraph -->
    <p><a href="#">%4$s</a></p>
    <!-- /wp:paragraph -->
    
    <!-- wp:paragraph -->
    <p><a href="#">%5$s</a></p>
    <!-- /wp:paragraph --></div>
    <!-- /wp:group -->
    ',
    esc_html_x( 'Learn More', 'Theme starter content', 'prespa' ),
    esc_html_x( 'About Us', 'Theme starter content', 'prespa' ),
    esc_html_x( 'Services', 'Theme starter content', 'prespa' ),
    esc_html_x( 'Customers', 'Theme starter content', 'prespa' ),
    esc_html_x( 'Newsletter', 'Theme starter content', 'prespa' )
);

return array(
    'prespa_text_3' => array(
        'text' ,
        array(
          'title' => null,
          'text'  => $default_content
        )
    )
);
