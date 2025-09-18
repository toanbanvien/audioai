<?php
/**
 * Registers block patterns categories, and type.
 */

function prespa_register_block_patterns() {
	$block_pattern_categories = array(
		'prespa' => array( 'label' => esc_html__( 'Prespa', 'prespa' ) ),
	);

	$block_pattern_categories = apply_filters( 'prespa_block_pattern_categories', $block_pattern_categories );

	if ( class_exists( 'WP_Block_Pattern_Categories_Registry' ) ) {
		foreach ( $block_pattern_categories as $name => $properties ) {
			if ( ! WP_Block_Pattern_Categories_Registry::get_instance()->is_registered( $name ) ) {
				register_block_pattern_category( $name, $properties );
			}
		}
	}
}

add_action( 'init', 'prespa_register_block_patterns', 9 );
