(function (api) {
	// Extends our custom "example-1" section.
	api.sectionConstructor['prespa-pro-section'] = api.Section.extend({
		// No events for this type of section.
		attachEvents() {},

		// Always make the section active.
		isContextuallyActive() {
			return true;
		},
	});
})(wp.customize);
