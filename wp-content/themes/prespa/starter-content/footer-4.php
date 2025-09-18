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
    <p>163 BD Lorem, Ipsum</p>
    <!-- /wp:paragraph -->
    <!-- wp:paragraph -->
    <p><a href="%2$s" target="_blank" rel="noreferrer noopener">%3$s</a></p>
    <!-- /wp:paragraph -->
    <!-- wp:paragraph -->
    <p>%4$s</p>
    <!-- /wp:paragraph --></div>
<!-- /wp:group -->
    ',
    esc_html_x( 'Get in Touch', 'Theme starter content', 'prespa' ),
    esc_url( 'mailto:info@example.com' ),
    esc_html_x( 'info@example.com', 'Theme starter content', 'prespa' ),
    esc_html_x( 'Opening Hours: 10:00 – 13:00', 'Theme starter content', 'prespa' )
);

return array(
    'prespa_text_4' => array(
        'text' ,
        array(
          'title' => null,
          'text'  => $default_content
        )
    )
);

return array(
    'prespa_text_4' => array(
        'text' ,
        array(
          'title' => null,
          'text'  => 
          '
        <!-- wp:group {"layout":{"type":"constrained"}} -->
            <div class="wp-block-group"><!-- wp:heading {"level":4} -->
            <h4 class="wp-block-heading">Get in Touch</h4>
            <!-- /wp:heading -->
            <!-- wp:paragraph -->
            <p>163 BD Lorem, Ipsum</p>
            <!-- /wp:paragraph -->
            <!-- wp:paragraph -->
            <p><a href="mailto:info@example.com" target="_blank" rel="noreferrer noopener">info@example.com</a></p>
            <!-- /wp:paragraph -->
            <!-- wp:paragraph -->
            <p>Opening Hours: 10:00 – 13:00</p>
            <!-- /wp:paragraph --></div>
        <!-- /wp:group -->
          '
        )
    )
);