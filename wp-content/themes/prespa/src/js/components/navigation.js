/**
 * File navigation.js.
 *
 * Handles toggling the navigation menu for small screens and enables TAB key
 * navigation support for dropdown menus.
 */
import { focusElements } from './helpers';

export default function navigation() {
	// header
	const header = document.getElementById( 'masthead' ) || '';

	if ( ! header ) return;

	//mobile menu
	const mainNavigation = document.getElementById( 'main-navigation' ) || '';
	//hamburger menu
	const menuButtons = document.querySelectorAll( '.site-menu .menu-toggle' );

	// Get all the link elements within the site menu
	let links = header.getElementsByTagName( 'a' );

	/**
	 * Toggle the .toggled class and the aria-expanded value
	 * each time a hamburger menu button is clicked or the enter button is pressed
	 */

	for ( let i = 0; i < menuButtons.length; i++ ) {
		menuButtons[ i ].addEventListener( 'click', function( e ) {
			toggleMobileMenu( e, this );
		} );
		menuButtons[ i ].addEventListener( 'keydown', function( e ) {
			// Number 13 is the "Enter" key on the keyboard
			if ( e.keyCode === 13 ) {
				toggleMobileMenu( e, this );
				mobileMenuFocusTrap( this );
			}
		} );
		menuButtons[ i ].addEventListener( 'focus', mobileMenuFocusSkip );
		menuButtons[ i ].addEventListener( 'blur', mobileMenuFocusSkip );
	}

	function toggleMobileMenu( e, $self ) {
		e.preventDefault();
		const toggledIcon = $self.childNodes[ 1 ].childNodes[ 1 ] || '';
		if ( toggledIcon )
			toggledIcon.checked = ! toggledIcon.checked;

		const toggledMenu = $self.parentNode;
		toggledMenu.classList.toggle( 'toggled' );
		if ( $self.className.indexOf( 'sub-menu-toggle' ) == -1 )
			document.body.classList.toggle( 'modal-open' );

		if ( $self.getAttribute( 'aria-expanded' ) === 'true' )
			$self.setAttribute( 'aria-expanded', 'false' );
		else
			$self.setAttribute( 'aria-expanded', 'true' );

		// Remove the .toggled class and set aria-expanded to false when the user clicks outside the navigation.
		document.addEventListener( 'click', function( e ) {
			// make a list of elements that will keep modal from closing
			const tagNames = [
				'label',
				'input',
				'a',
				'i',
				'svg',
				'circle',
				'path',
				'line',
				'button',
				'span',
			];
			let isClickInside = false;
			for ( let i = 0; i < tagNames.length; i++ )
				if ( tagNames[ i ] === e.target.tagName.toLowerCase() )
					isClickInside = true;

			//Close the modal when user clicks outside the menu links and the hamburger
			if ( ! isClickInside ) {
				toggledMenu.className = toggledMenu.className.replace(
					'toggled',
					''
				);
				document.body.className = document.body.className.replace(
					'modal-open',
					''
				);
				$self.setAttribute( 'aria-expanded', 'false' );
				if ( toggledIcon )
					toggledIcon.checked = false;
			}
		} );
	}

	// Toggle focus each time a menu link is focused or blurred.
	setTimeout( function() {
		links = header.getElementsByTagName( 'a' );

		for ( let i = 0, len = links.length; i < len; i++ ) {
			if ( window.matchMedia( '(max-width: 864px)' ).matches )
				return;

			links[ i ].addEventListener( 'focus', toggleFocus, true );
			links[ i ].addEventListener( 'blur', toggleFocus, true );
		}
	}, 700 );

	/**
	 * Sets or removes .focus class on an element.
	 *
	 * @param e
	 */
	function toggleFocus( e ) {
		if ( e.type === 'focus' || e.type === 'blur' ) {
			let self = this;
			// Move up through the ancestors of the current link until we hit .nav-menu.
			while (
				typeof self.className !== 'undefined' &&
				-1 === self.className.indexOf( 'nav-menu' )
			) {
				// On li elements toggle the class .focus.
				if ( 'li' === self.tagName.toLowerCase() )
					if ( -1 !== self.className.indexOf( 'focus' ) )
						self.className = self.className.replace( ' focus', '' );
					else
						self.className += ' focus';

				self = self.parentNode;
			}
		}

		if ( e.type === 'touchstart' ) {
			const menuItem = this.parentNode;
			e.preventDefault();
			for ( let i = 0; i < menuItem.parentNode.children.length; ++i ) {
				const link = menuItem.parentNode.children[ i ];
				if ( menuItem !== link )
					link.classList.remove( 'focus' );
			}
			menuItem.classList.toggle( 'focus' );
		}
	}

	/**
	 * Mobile menu logic
	 * * @param $self
	 */

	// Set the trap. Loop through mobile menu items on focus until the menu is closed
	function mobileMenuFocusTrap( $self ) {
		if ( window.matchMedia( '(min-width: 864px)' ).matches )
			return;

		const focusableItems = header.querySelectorAll(
			'.menu-toggle, .menu-item > a, .social-icon > a, .site-header-cart > a, .search-icon > button, .dark-mode-widget'
		);
		const firstFocusableElement = focusableItems[ 0 ]; // get first element to be focused inside modal
		const lastFocusableElement = focusableItems[ focusableItems.length - 1 ]; // get last element to be focused inside modal

		mainNavigation.addEventListener( 'keydown', function( e ) {
			if ( $self.getAttribute( 'aria-expanded' ) == 'false' )
				return;

			if ( e.keyCode == '27' ) {
				// Escape key
				toggleMobileMenu( e, this.firstElementChild );
				this.firstElementChild.focus();
			}
			focusElements( firstFocusableElement, lastFocusableElement, e );
		} );
	}

	// Search modal focus trap
	function searchModalFocusTrap() {
		if ( window.matchMedia( '(max-width: 864px)' ).matches )
			return;

		const focusableItems = this.querySelectorAll(
			'input, .search-form button, .close'
		);
		const firstFocusableElement = focusableItems[ 0 ]; // get first element to be focused inside modal
		const lastFocusableElement = focusableItems[ focusableItems.length - 1 ]; // get last element to be focused inside modal

		this.addEventListener( 'keydown', function( e ) {
			focusElements( firstFocusableElement, lastFocusableElement, e );
		} );
	}

	document.getElementById( 'search-open' ).addEventListener( 'keydown', searchModalFocusTrap );

	// Resume keyboard navigation after search modal is closed via keyboard
	document.querySelector( '#search-open .close' ).addEventListener( 'keydown', function( e ) {
		// Number 13 is the "Enter" key on the keyboard
		if ( e.keyCode === 13 )
			setTimeout( function() {
				document.querySelector( '.search-icon a' ).focus();
			}, 100 );
	} );
	// Resume keyboard navigation after search modal is closed via button click
	document.querySelector( '#search-open .close' ).addEventListener( 'click', function( ) {
		setTimeout( function() {
			document.querySelector( '.search-icon a' ).focus();
		}, 200 );
	} );

	// Skip mobile menu if not opened
	function mobileMenuFocusSkip() {
		if ( window.matchMedia( '(min-width: 864px)' ).matches ) return;

		const buttons = document.querySelectorAll( '.slide-menu button' );

		menuButtons[ 0 ].addEventListener( 'keydown', function( e ) {
			if (
				this.getAttribute( 'aria-expanded' ) == 'false' &&
				e.keyCode !== 13
			) {
				for ( let i = 0; i < links.length; i++ )
					if ( links[ i ].parentNode.tagName.toLowerCase() == 'li' )
						links[ i ].setAttribute( 'tabindex', '-1' );

				for ( let i = 0; i < buttons.length; i++ )
					buttons[ i ].setAttribute( 'tabindex', '-1' );
			} else {
				for ( let i = 0; i < links.length; i++ )
					links[ i ].removeAttribute( 'tabindex' );

				for ( let i = 0; i < buttons.length; i++ )
					buttons[ i ].removeAttribute( 'tabindex' );
			}
		} );
	}

	//unload burger menu on page load
	document.getElementById( 'burger-check' ).checked = false;
}
