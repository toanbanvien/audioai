jQuery(function ($) {
	'use strict';

	/**
	 * Sortable Repeater Custom Control
	 *
	 * @author Anthony Hortin <http://maddisondesigns.com>
	 * @license http://www.gnu.org/licenses/gpl-2.0.html
	 * @link https://github.com/maddisondesigns
	 */

	// Update the values for all our input fields and initialise the sortable repeater
	$('.sortable_repeater_control').each(function () {
		// If there is an existing customizer value, populate our rows
		const defaultValuesArray = $(this)
			.find('.customize-control-sortable-repeater')
			.val()
			.split(',');
		const numRepeaterItems = defaultValuesArray.length;

		if (numRepeaterItems > 0) {
			// Add the first item to our existing input field
			$(this).find('.repeater-input').val(defaultValuesArray[0]);
			// Create a prespa row for each prespa value
			if (numRepeaterItems > 1) {
				let i;
				for (i = 1; i < numRepeaterItems; ++i) {
					newAppendRow($(this), defaultValuesArray[i]);
				}
			}
		}
	});

	// Make our Repeater fields sortable
	$(this)
		.find('.sortable_repeater.sortable')
		.sortable({
			update(event, ui) {
				newGetAllInputs($(this).parent());
			},
		});

	// Remove item starting from it's parent element
	$('.sortable_repeater.sortable').on(
		'click',
		'.customize-control-sortable-repeater-delete',
		function (event) {
			event.preventDefault();
			const numItems = $(this).parent().parent().find('.repeater').length;

			if (numItems > 1) {
				$(this)
					.parent()
					.slideUp('fast', function () {
						const parentContainer = $(this).parent().parent();
						$(this).remove();
						newGetAllInputs(parentContainer);
					});
			} else {
				$(this).parent().find('.repeater-input').val('');
				newGetAllInputs($(this).parent().parent().parent());
			}
		}
	);

	// Add prespa item
	$('.customize-control-sortable-repeater-add').click(function (event) {
		event.preventDefault();
		newAppendRow($(this).parent());
		newGetAllInputs($(this).parent());
	});

	// Refresh our hidden field if any fields change
	$('.sortable_repeater.sortable').change(function () {
		newGetAllInputs($(this).parent());
	});

	// Add https:// to the start of the URL if it doesn't have it
	$('.sortable_repeater.sortable').on('blur', '.repeater-input', function () {
		const url = $(this);
		const val = url.val();
		if (val && !val.match(/^.+:\/\/.*/)) {
			// Important! Make sure to trigger change event so Customizer knows it has to save the field
			url.val('https://' + val).trigger('change');
		}
	});

	// Append a prespa row to our list of elements
	function newAppendRow($element, defaultValue = '') {
		const newRow =
			'<div class="repeater" style="display:none"><input type="text" value="' +
			defaultValue +
			'" class="repeater-input" placeholder="https://" /><span class="dashicons dashicons-sort"></span><a class="customize-control-sortable-repeater-delete" href="#"><span class="dashicons dashicons-no-alt"></span></a></div>';

		$element.find('.sortable').append(newRow);
		$element
			.find('.sortable')
			.find('.repeater:last')
			.slideDown('slow', function () {
				$(this).find('input').focus();
			});
	}

	// Get the values from the repeater input fields and add to our hidden field
	function newGetAllInputs($element) {
		const inputValues = $element
			.find('.repeater-input')
			.map(function () {
				return $(this).val();
			})
			.toArray();
		// Add all the values from our repeater fields to the hidden field (which is the one that actually gets saved)
		$element.find('.customize-control-sortable-repeater').val(inputValues);
		// Important! Make sure to trigger change event so Customizer knows it has to save the field
		$element.find('.customize-control-sortable-repeater').trigger('change');
	}

	/**
	 * Pill Checkbox Custom Control
	 *
	 * @author Anthony Hortin <http://maddisondesigns.com>
	 * @license http://www.gnu.org/licenses/gpl-2.0.html
	 * @link https://github.com/maddisondesigns
	 */

	$('.pill_checkbox_control .sortable').sortable({
		placeholder: 'pill-ui-state-highlight',
		update(event, ui) {
			newGetAllPillCheckboxes($(this).parent());
		},
	});

	$('.pill_checkbox_control .sortable-pill-checkbox').on(
		'change',
		function () {
			newGetAllPillCheckboxes($(this).parent().parent().parent());
		}
	);

	// Get the values from the checkboxes and add to our hidden field
	function newGetAllPillCheckboxes($element) {
		const inputValues = $element
			.find('.sortable-pill-checkbox')
			.map(function () {
				if ($(this).is(':checked')) {
					return $(this).val();
				}
			})
			.toArray();
		$element
			.find('.customize-control-sortable-pill-checkbox')
			.val(inputValues)
			.trigger('change');
	}

	/**
	 * Single Accordion Custom Control
	 *
	 * @author Anthony Hortin <http://maddisondesigns.com>
	 * @license http://www.gnu.org/licenses/gpl-2.0.html
	 * @link https://github.com/maddisondesigns
	 */

	$('.single-accordion-toggle').click(function () {
		const $accordionToggle = $(this);
		$(this)
			.parent()
			.find('.single-accordion')
			.slideToggle('slow', function () {
				$accordionToggle.toggleClass(
					'single-accordion-toggle-rotate',
					$(this).is(':visible')
				);
			});
	});
});

/**
 * Remove attached events from the Upsell Section to stop panel from being able to open/close
 *
 * @param $
 * @param api
 */

/**
 * Slider range control
 *
 * @param $
 */
(function ($) {
	wp.customize.controlConstructor['prespa-range-slider'] =
		wp.customize.Control.extend({
			ready() {
				'use strict';

				let control = this,
					value,
					controlClass = '.customize-control-prespa-range-slider',
					footerActions = $('#customize-footer-actions');

				// Set up the sliders
				$('.p-slider').each(function () {
					const _this = $(this);
					const _input = _this
						.closest('label')
						.find('input[type="number"]');
					const _text = _input.next('.value');
					_this.slider({
						value: _input.val(),
						min: _this.data('min'),
						max: _this.data('max'),
						step: _this.data('step'),
						slide(event, ui) {
							_input.val(ui.value).change();
							_text.text(ui.value);
						},
					});
				});

				// Update the range value based on the input value
				$(controlClass + ' .gp_range_value input[type=number]').on(
					'input',
					function () {
						value = $(this).val();
						if ('' == value) {
							value = -1;
						}
						$(this)
							.closest('label')
							.find('.p-slider')
							.slider('value', parseFloat(value))
							.change();
					}
				);

				// Handle the reset button
				$(controlClass + ' .prespa-reset').on('click', function () {
					let icon = $(this),
						visible_area = icon
							.closest('.gp-range-title-area')
							.next('.gp-range-slider-areas')
							.children('label:visible'),
						input = visible_area.find('input[type=number]'),
						slider_value = visible_area.find('.p-slider'),
						visual_value = visible_area.find('.gp_range_value'),
						reset_value = input.attr('data-reset_value');

					input.val(reset_value).change();
					visual_value.find('input').val(reset_value);
					visual_value.find('.value').text(reset_value);

					if ('' == reset_value) {
						reset_value = -1;
					}

					slider_value.slider('value', parseFloat(reset_value));
				});

				// Figure out which device icon to make active on load
				$(controlClass + ' .prespa-range-slider-control').each(
					function () {
						const _this = $(this);
						_this
							.find('.gp-device-controls')
							.children('span:first-child')
							.addClass('selected');
						_this.find('.range-option-area:first-child').show();
					}
				);

				// Do stuff when device icons are clicked
				$(controlClass + ' .gp-device-controls > span').on(
					'click',
					function (event) {
						const device = $(this).data('option');

						$(controlClass + ' .gp-device-controls span').each(
							function () {
								const _this = $(this);
								if (device == _this.attr('data-option')) {
									_this.addClass('selected');
									_this.siblings().removeClass('selected');
								}
							}
						);

						$(controlClass + ' .gp-range-slider-areas label').each(
							function () {
								const _this = $(this);
								if (device == _this.attr('data-option')) {
									_this.show();
									_this.siblings().hide();
								}
							}
						);

						// Set the device we're currently viewing
						wp.customize.previewedDevice.set(
							$(event.currentTarget).data('option')
						);
					}
				);

				// Set the selected devices in our control when the Customizer devices are clicked
				footerActions.find('.devices button').on('click', function () {
					const device = $(this).data('device');
					$(controlClass + ' .gp-device-controls span').each(
						function () {
							const _this = $(this);
							if (device == _this.attr('data-option')) {
								_this.addClass('selected');
								_this.siblings().removeClass('selected');
							}
						}
					);

					$(controlClass + ' .gp-range-slider-areas label').each(
						function () {
							const _this = $(this);
							if (device == _this.attr('data-option')) {
								_this.show();
								_this.siblings().hide();
							}
						}
					);
				});

				// Apply changes when desktop slider is changed
				control.container.on(
					'input change',
					'.desktop-range',
					function () {
						control.settings.desktop.set($(this).val());
					}
				);
			},
		});
})(jQuery);
