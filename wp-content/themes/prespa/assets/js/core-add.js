/*
 * This JS file is loaded on the site frontend and block editor at the same time
 */

(function () {
	// Adds wp-button-element class to block editor's button links for backwards compatibility
	const buttons = document.querySelectorAll('.wp-block-button__link');

	for (let i = 0; i < buttons.length; i++) {
		if (buttons[i].className.indexOf('wp-element-button') == -1) {
			buttons[i].className += ' wp-element-button';
		}
	}
})();
