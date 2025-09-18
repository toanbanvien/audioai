import { animator, isInViewport } from './helpers.js';
import { runStatsAnimation } from './patterns/index.js';

// animate post archives
export function postLoopAnimation() {
	if (
		document.body.className.indexOf( 'single' ) > -1 ||
		document.body.className.indexOf( 'page ' ) > -1
	) {
		return;
	}
	const articles = document.getElementsByClassName( 'hentry' );
	animator( articles );
}

// for blog posts in viewport, run the animation on page load for better ui
export function articlesInViewport() {
	if (
		document.body.className.indexOf( 'single' ) > -1 ||
		document.body.className.indexOf( 'page ' ) > -1
	) {
		return;
	}
	const articles = document.getElementsByClassName( 'hentry' );
	for ( let i = 0; i < articles.length; i++ ) {
		if ( isInViewport( articles[ i ] ) ) {
			articles[ i ].className += ' animated';
		}
	}
}
// for block patterns in viewport, run the animation on page load for better ui
export function blocksInViewport() {
	const allElements = document.querySelectorAll(
		'[class^="p-animation"],[class*=" p-animation"]'
	);
	for ( let i = 0; i < allElements.length; i++ ) {
		if ( isInViewport( allElements[ i ] ) ) {
			allElements[ i ].className += ' animated';
		}
	}
	// customizer preview fix
	const previewedElements = document.querySelectorAll(
		'.hero-pattern .wp-block-columns, .header-pattern .wp-block-columns'
	);

	for ( let i = 0; i < previewedElements.length; i++ ) {
		previewedElements[ i ].classList.add( 'animated' );
	}
	runStatsAnimation();
}

//animate the static homepage
export function blocksAnimation() {
	if ( document.body.className.indexOf( 'page' ) == -1 ) {
		return;
	}
	const allElements = document.querySelectorAll(
		'[class^="p-animation"],[class*=" p-animation"]'
	);
	animator( allElements, 60 ); // distance from top element. optional.
}

// typewrite animation

export function typingAnimation() {
	/**
	 * Wrap the animated text content in span tags
	 */
	if ( ! document.querySelector( '.p-typewrite-animation' ) ) {
		return;
	}
	const p = document.querySelectorAll( '.p-typewrite-animation' );

	for ( let i = 0; i < p.length; i++ ) {
		const pHtml = p[ i ].innerHTML;
		const span = document.createElement( 'span' );
		span.innerHTML = pHtml;
		p[ i ].innerHTML = ''; // Clear existing content
		p[ i ].appendChild( span ); // Add the span

		const spanElement = p[ i ].querySelector( 'span' );
		const h = p[ i ].clientHeight;
		spanElement.innerHTML = ''; // Clear span for animation
		p[ i ].style.visibility = 'visible';
		p[ i ].style.opacity = '1';
		p[ i ].style.height = h + 'px';

		// Split by characters, but keep HTML tags intact
		const strArray = pHtml.match( /(<[^>]+>|[^<]+)/g );

		let index = 0;
		function animate() {
			if ( index < strArray.length ) {
				const current = strArray[ index ];

				if ( current.startsWith( '<' ) ) {
					// It's an HTML tag (like <br> or <span>), append it fully
					spanElement.innerHTML += current;
				} else {
					// It's a regular text, add it character by character
					const textChars = current.split( '' );
					let textIndex = 0;

					function animateText() {
						if ( textIndex < textChars.length ) {
							spanElement.innerHTML += textChars[ textIndex ];
							textIndex++;
							setTimeout( animateText, 45 );
						} else {
							index++;
							animate();
						}
					}

					animateText();
					return;
				}

				index++;
				setTimeout( animate, 45 );
			}
		}

		setTimeout( animate, 900 );
	}
}
