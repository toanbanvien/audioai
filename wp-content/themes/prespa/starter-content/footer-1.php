<?php
/**
 * Sidebar 1 starter content.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

$default_content = sprintf(
    '
    <!-- wp:group {"layout":{"type":"constrained"}} -->
    <div class="wp-block-group"><!-- wp:heading {"level":4} -->
    <h4 class="wp-block-heading"><a href="#">%1$s</a></h4>
    <!-- /wp:heading -->
    <!-- wp:paragraph -->
    <p>%2$s</p>
    <!-- /wp:paragraph --></div>
    <!-- /wp:group -->
    ',
    esc_html_x( 'Prespa', 'Theme starter content', 'prespa' ),
    esc_html_x( 'Let\'s make something cool together. We are trusted by over 3000+ clients who can\'t be wrong.', 'Theme starter content', 'prespa' )
);

return array(
    'prespa_text' => array(
        'text' ,
        array(
          'title' => null,
          'text'  => $default_content
        )
    )
);
