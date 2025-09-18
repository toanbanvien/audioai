export function darkMode() {
	const body = document.body;
	const switchers = document.getElementsByClassName( 'dark-mode-widget' );
	/**
	 * Click on dark mode icon. Add dark mode classes and wrappers.
	 * Store user preference through sessions
	 */
	for ( let i = 0; i < switchers.length; i++ ) {
		switchers[ i ].addEventListener( 'click', function() {
			if ( ! this.classList.contains( 'js-toggle--checked' ) ) {
				this.classList.add( 'js-toggle--checked' );
				body.classList.add( 'dark-mode' );
				//Save user preference in storage
				localStorage.setItem( 'prespaNightMode', 'true' );
			} else {
				this.classList.remove( 'js-toggle--checked' );
				body.classList.remove( 'dark-mode' );
				setTimeout( function() {
					localStorage.removeItem( 'prespaNightMode' );
				}, 100 );
			}
		} );
	}

	//Check Storage. Keep user preference on page reload
	if ( localStorage.getItem( 'prespaNightMode' ) ) {
		for ( let i = 0; i < switchers.length; i++ ) {
			switchers[ i ].classList.add( 'js-toggle--checked' );
			body.classList.add( 'dark-mode' );
		}
	}
}
