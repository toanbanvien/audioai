<?php
/**
 * Single Accordion Custom Control
 *
 * @author Anthony Hortin <http://maddisondesigns.com>
 * @license http://www.gnu.org/licenses/gpl-2.0.html
 * @link https://github.com/maddisondesigns
 */
if ( class_exists( 'WP_Customize_Control' ) && ! class_exists( 'Prespa_Single_Accordion_Custom_Control' ) ) {

	class Prespa_Single_Accordion_Custom_Control extends WP_Customize_Control {
		/**
		 * The type of control being rendered
		 */
		public $type = 'single_accordion';
		/**
		 * Enqueue our scripts and styles
		 */
		public function enqueue() {
			wp_enqueue_script( 'prespa-custom-controls-js', trailingslashit( get_template_directory_uri() ) . 'inc/customizer-controls/js/custom-controls.js', array( 'jquery', 'jquery-ui-core' ), '1.0', true );
		}
		/**
		 * Render the control in the customizer
		 */
		public function render_content() {
			?>
			<div class="single-accordion-custom-control">
				<div class="single-accordion-toggle"><?php echo esc_html( $this->label ); ?><span class="accordion-icon-toggle dashicons dashicons-plus"></span></div>
				<div class="single-accordion customize-control-description">
					<?php
					if ( is_array( $this->description ) ) {
						echo '<ul class="single-accordion-description">';
						foreach ( $this->description as $key => $value ) {
							echo '<li>' . $key . wp_kses( $value, prespa_get_kses_extended_ruleset() ) . '</li>';
						}
						echo '</ul>';
					} else {
						echo wp_kses( $this->description, prespa_get_kses_extended_ruleset() );
					}
					?>
				</div>
			</div>
			<?php
		}
	}
}
