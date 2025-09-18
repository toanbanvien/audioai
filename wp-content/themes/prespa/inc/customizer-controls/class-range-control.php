<?php
/**
 * The range slider Customizer control.
 *
 * @author Tom Usborne
 * @license http://www.gnu.org/licenses/gpl-2.0.html
 * @link https://github.com/tomusborne/generatepress/
 */

if ( class_exists( 'WP_Customize_Control' ) && ! class_exists( 'Prespa_Range_Slider_Control' ) ) {
	/**
	 * Create a range slider control.
	 * This control allows you to add responsive settings.
	 *
	 * @since 1.3.47
	 */
	class Prespa_Range_Slider_Control extends WP_Customize_Control {
		/**
		 * The control type.
		 *
		 * @access public
		 * @var string
		 */
		public $type = 'prespa-range-slider';

		/**
		 * The control description.
		 *
		 * @access public
		 * @var string
		 */
		public $description = '';

		/**
		 * The control sub-description.
		 *
		 * @access public
		 * @var string
		 */
		public $sub_description = '';

		/**
		 * Refresh the parameters passed to the JavaScript via JSON.
		 *
		 * @see WP_Customize_Control::to_json()
		 */
		public function to_json() {
			parent::to_json();

			$devices = array( 'desktop', 'tablet', 'mobile' );
			foreach ( $devices as $device ) {
				$this->json['choices'][ $device ]['min']  = ( isset( $this->choices[ $device ]['min'] ) ) ? $this->choices[ $device ]['min'] : '0';
				$this->json['choices'][ $device ]['max']  = ( isset( $this->choices[ $device ]['max'] ) ) ? $this->choices[ $device ]['max'] : '100';
				$this->json['choices'][ $device ]['step'] = ( isset( $this->choices[ $device ]['step'] ) ) ? $this->choices[ $device ]['step'] : '1';
				$this->json['choices'][ $device ]['edit'] = ( isset( $this->choices[ $device ]['edit'] ) ) ? $this->choices[ $device ]['edit'] : false;
				$this->json['choices'][ $device ]['unit'] = ( isset( $this->choices[ $device ]['unit'] ) ) ? $this->choices[ $device ]['unit'] : false;
			}

			foreach ( $this->settings as $setting_key => $setting_id ) {
				$this->json[ $setting_key ] = array(
					'link'    => $this->get_link( $setting_key ),
					'value'   => $this->value( $setting_key ),
					'default' => isset( $setting_id->default ) ? $setting_id->default : '',
				);
			}

			$this->json['desktop_label'] = esc_html__( 'Desktop', 'prespa' );
			$this->json['tablet_label']  = esc_html__( 'Tablet', 'prespa' );
			$this->json['mobile_label']  = esc_html__( 'Mobile', 'prespa' );
			$this->json['reset_label']   = esc_html__( 'Reset', 'prespa' );

			$this->json['description']     = $this->description;
			$this->json['sub_description'] = $this->sub_description;
		}

		/**
		 * Enqueue control related scripts/styles.
		 *
		 * @access public
		 */
		public function enqueue() {
			wp_enqueue_script( 'prespa-custom-controls-js', trailingslashit( get_template_directory_uri() ) . 'inc/customizer-controls/js/custom-controls.js', array( 'jquery', 'customize-base', 'jquery-ui-slider' ), PRESPA_VERSION, true );
			wp_enqueue_style( 'prespa-custom-controls-css', trailingslashit( get_template_directory_uri() ) . 'inc/customizer-controls/css/custom-controls.css', array(), PRESPA_VERSION );
		}

		/**
		 * An Underscore (JS) template for this control's content (but not its container).
		 *
		 * Class variables for this control class are available in the `data` JS object;
		 * export custom variables by overriding {@see WP_Customize_Control::to_json()}.
		 *
		 * @see WP_Customize_Control::print_template()
		 *
		 * @access protected
		 */
		protected function content_template() {
			?>
			<div class="prespa-range-slider-control">
				<div class="gp-range-title-area">
					<# if ( data.label || data.description ) { #>
						<div class="gp-range-title-info">
							<# if ( data.label ) { #>
								<span class="customize-control-title">{{{ data.label }}}</span>
							<# } #>

							<# if ( data.description ) { #>
								<p class="description">{{{ data.description }}}</p>
							<# } #>
						</div>
					<# } #>

					<div class="gp-range-slider-controls">
						<span class="gp-device-controls">
							<# if ( 'undefined' !== typeof ( data.desktop ) ) { #>
								<span class="prespa-device-desktop dashicons dashicons-desktop" data-option="desktop" title="{{ data.desktop_label }}"></span>
							<# } #>
						</span>

						<span title="{{ data.reset_label }}" class="prespa-reset dashicons dashicons-image-rotate"></span>
					</div>
				</div>

				<div class="gp-range-slider-areas">
					<# if ( 'undefined' !== typeof ( data.desktop ) ) { #>
						<label class="range-option-area" data-option="desktop" style="display: none;">
							<div class="wrapper <# if ( '' !== data.choices['desktop']['unit'] ) { #>has-unit<# } #>">
								<div class="p-slider" data-step="{{ data.choices['desktop']['step'] }}" data-min="{{ data.choices['desktop']['min'] }}" data-max="{{ data.choices['desktop']['max'] }}"></div>

								<div class="gp_range_value <# if ( '' == data.choices['desktop']['unit'] && ! data.choices['desktop']['edit'] ) { #>hide-value<# } #>">
									<input <# if ( data.choices['desktop']['edit'] ) { #>style="display:inline-block;"<# } else { #>style="display:none;"<# } #> type="number" step="{{ data.choices['desktop']['step'] }}" class="desktop-range value" value="{{ data.desktop.value }}" min="{{ data.choices['desktop']['min'] }}" max="{{ data.choices['desktop']['max'] }}" {{{ data.desktop.link }}} data-reset_value="{{ data.desktop.default }}" />
									<span <# if ( ! data.choices['desktop']['edit'] ) { #>style="display:inline-block;"<# } else { #>style="display:none;"<# } #> class="value">{{ data.desktop.value }}</span>

									<# if ( data.choices['desktop']['unit'] ) { #>
										<span class="unit">{{ data.choices['desktop']['unit'] }}</span>
									<# } #>
								</div>
							</div>
						</label>
					<# } #>
				</div>

				<# if ( data.sub_description ) { #>
					<p class="description sub-description">{{{ data.sub_description }}}</p>
				<# } #>
			</div>
			<?php
		}
	}
}
