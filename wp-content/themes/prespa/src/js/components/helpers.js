/** HELPERS */

// helper function for the focus trap
export function focusElements( firstEl, lastEl, e ) {
	if ( e.key !== 'Tab' || e.keyCode !== 9 ) {
		return;
	}
	if ( e.shiftKey ) {
		// if shift key pressed for shift + tab combination
		// eslint-disable-next-line @wordpress/no-global-active-element
		if ( document.activeElement === firstEl ) {
			lastEl.focus(); // add focus for the last focusable element
			e.preventDefault();
		}
		// if tab key is pressed
		// eslint-disable-next-line @wordpress/no-global-active-element
	} else if ( document.activeElement == lastEl ) {
		// if focused has reached to last focusable element then focus first focusable element after pressing tab
		firstEl.focus(); // add focus for the first focusable element
		e.preventDefault();
	}
}

// Helper function to animate all elements in viewport
export function animator( articles, distance = 5 ) {
	for ( let i = 0; i < articles.length; i++ ) {
		const article = articles[ i ];
		const visible = isInViewport( article, distance );
		if ( visible ) {
			if ( article.className.indexOf( 'animated' ) == -1 ) {
				article.className += ' animated';
			}
		}
	}
}

// Helper to check if element is in viewport
export function isInViewport( element, distance = 5 ) {
	const context = element.ownerDocument.defaultView || window;
	const rect = element.getBoundingClientRect();
	const elemTop = rect.top + distance;
	const elemBottom = rect.bottom;
	const isVisible = elemTop < context.innerHeight && elemBottom >= 0;
	return isVisible;
}

// Helper function to optimize the expensive on-scroll events
export function debounce( func, wait, immediate ) {
	let timeout;
	return function() {
		const context = this,
			args = arguments;
		const later = function() {
			timeout = null;
			if ( ! immediate ) {
				func.apply( context, args );
			}
		};
		const callNow = immediate && ! timeout;
		clearTimeout( timeout );
		timeout = setTimeout( later, wait );
		if ( callNow ) {
			func.apply( context, args );
		}
	};
}

// Css variables generator
export function createCSSVariable( element, variableName, value ) {
	element.style.setProperty( variableName, value );
}
