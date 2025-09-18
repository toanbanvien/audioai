import { debounce } from './helpers';

const scrolledToBottom = function() {
	return window.innerHeight + window.scrollY >= document.body.scrollHeight;
};

/* add fixed and sticky menu classes and handle page layout */
export function fixedMenu() {
	const fixedHeader = prespa_customizer_object.fixed_header;
	const stickyHeader = prespa_customizer_object.sticky_header;

	//Return if static header
	if ( ! fixedHeader && ! stickyHeader ) {
		return;
	}
	// Ensure smooth user experience when page is empty and you have reached the bottom of the page
	if ( scrolledToBottom() ) {
		return;
	}

	const scrollBarPosition = window.pageYOffset | document.body.scrollTop;
	const primaryMenu = document.getElementsByClassName(
		'main-navigation-container'
	)[ 0 ];

	if ( scrollBarPosition > 50 ) {
		if ( primaryMenu.className.indexOf( 'fixed-header' ) == -1 ) {
			primaryMenu.className += ' fixed-header';
			primaryMenu.style.position = 'fixed';
		}
	} else {
		primaryMenu.className = primaryMenu.className.replace(
			' fixed-header',
			''
		);
		primaryMenu.style.position = prespa_customizer_object.has_hero_image
			? 'fixed'
			: 'static';
	}

	if ( stickyHeader ) {
		if ( scrollBarPosition > 50 ) {
			if ( primaryMenu.className.indexOf( 'sticky-header' ) == -1 ) {
				primaryMenu.className += ' sticky-header';
			}
		} else {
			primaryMenu.className = primaryMenu.className.replace(
				'sticky-header',
				''
			);
		}
	}
}

// Sticky Header functionality
export function stickyHeader() {
	if ( prespa_customizer_object.sticky_header !== '1' ) {
		return;
	}
	const doc = document.documentElement;
	const w = window;

	let prevScroll = w.scrollY || doc.scrollTop;
	let curScroll;
	let direction = 0;
	let prevDirection = 0;

	function checkScroll() {
		curScroll = w.scrollY || doc.scrollTop;
		if ( curScroll > prevScroll ) {
			//scrolled up
			direction = 'up';
		} else if ( curScroll < prevScroll ) {
			//scrolled down
			direction = 'down';
		}

		if ( direction !== prevDirection ) {
			toggleHeader( direction, curScroll, prevDirection );
		}

		prevScroll = curScroll;
	}

	function toggleHeader( direction, curScroll ) {
		const header = document.getElementsByClassName(
			'main-navigation-container'
		)[ 0 ];
		if ( direction === 'up' && curScroll > 50 ) {
			header.classList.remove( 'show' );
			prevDirection = direction;
		} else if ( direction === 'down' ) {
			header.classList.add( 'show' );
			prevDirection = direction;
		}
	}

	window.addEventListener( 'scroll', debounce( checkScroll, 15 ) );
}
