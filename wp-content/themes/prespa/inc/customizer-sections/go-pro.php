<?php

function prespa_customize_script(){
    wp_enqueue_style( 'prespa-customize-go-pro', get_template_directory_uri() . '/inc/customizer-sections/customize.css', array(), PRESPA_VERSION );
    wp_enqueue_script( 'prespa-customize-go-pro', get_template_directory_uri() . '/inc/customizer-sections/customize.js', array( 'jquery', 'customize-controls' ), PRESPA_VERSION, true );

}

add_action( 'customize_controls_enqueue_scripts', 'prespa_customize_script' );

/**
 * Add Go Pro Button
*/

if( class_exists( 'WP_Customize_Section' ) ) :
    /**
     * Adding Go to Pro Section in Customizer
     * @link https://github.com/justintadlock/trt-customizer-pro
     * @example https://wordpress.org/themes/blossom-travel/
     * 
     */
    class Prespa_Customize_Section_Pro extends WP_Customize_Section {
    
        /**
         * The type of customize section being rendered.
         *
         * @since  1.0.0
         * @access public
         * @var    string
         */
        public $type = 'prespa-pro-section';
    
        /**
         * Custom button text to output.
         *
         * @since  1.0.0
         * @access public
         * @var    string
         */
        public $pro_text = '';
    
        /**
         * Custom pro button URL.
         *
         * @since  1.0.0
         * @access public
         * @var    string
         */
        public $pro_url = '';
    
        /**
         * Add custom parameters to pass to the JS via JSON.
         *
         * @since  1.0.0
         * @access public
         * @return void
         */
        public function json() {
            $json = parent::json();
    
            $json['pro_text'] = $this->pro_text;
            $json['pro_url']  = esc_url( $this->pro_url );
    
            return $json;
        }
    
        /**
         * Outputs the Underscore.js template.
         *
         * @since  1.0.0
         * @access public
         * @return void
         */
        protected function render_template() { ?>
            <li id="accordion-section-{{ data.id }}" class="accordion-section control-section control-section-{{ data.type }} cannot-expand">
                <div class="accordion-section-title go-pro-section">
                    <# if ( data.pro_text && data.pro_url ) { #>
                        <a href="{{ data.pro_url }}" class="button button-secondary button-red" target="_blank">{{ data.pro_text }}</a>
                    <# } #>
                </div>
            </li>
        <?php }
    }

endif;

function prespa_page_sections_pro( $wp_customize ) {
	// Register custom section types.
	$wp_customize->register_section_type( 'Prespa_Customize_Section_Pro' );

	// Register sections.
	$wp_customize->add_section(
		new Prespa_Customize_Section_Pro(
			$wp_customize,
			'prespa_page_view_pro',
			array(
				'title'    => esc_html__( 'Pro Available', 'prespa' ),
                'priority' => 5, 
				'pro_text' => esc_html__( 'Upgrade to PRO', 'prespa' ),
				'pro_url'  => esc_url( 'https://nasiothemes.com/themes/prespa/' ),
			)
		)
	);
}
add_action( 'customize_register', 'prespa_page_sections_pro' );