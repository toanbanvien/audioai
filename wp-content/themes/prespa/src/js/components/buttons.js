import { createCSSVariable } from './helpers';

/**
 * Generate a css variable that inherits the button background color
 * Used for the border animation
 *
 * @param button
 */
function inheritButtonBackgroundColor( button ) {
	if (
		document.getElementsByClassName( 'p-btn-animation-border-move' ).length ==
		0
	) {
		return;
	}
	const bgColor =
		getComputedStyle( button ).backgroundColor == 'rgba(0, 0, 0, 0)'
			? getComputedStyle( button ).borderColor
			: getComputedStyle( button ).backgroundColor;
	createCSSVariable( button, '--border-color', bgColor );
}

export function wrapButtons() {
	/**
	 * Wrap buttons in span tags and add data attribute the title of the link
	 * We need this for styling purposes
	 */
	const buttons = document.querySelectorAll( '.wp-block-button__link' );

	for ( let i = 0; i < buttons.length; i++ ) {
		let span = buttons[ i ].querySelector( 'span' ) || null;
		if ( span ) {
			buttons[ i ].setAttribute( 'data-text', span.innerText );
			inheritButtonBackgroundColor( buttons[ i ] );
			continue;
		}
		span = document.createElement( 'span' );
		span.innerText = buttons[ i ].innerText;
		buttons[ i ].innerHTML = ''; // clean up
		buttons[ i ].appendChild( span );
		buttons[ i ].setAttribute( 'data-text', span.innerText );
		inheritButtonBackgroundColor( buttons[ i ] );
	}
}
