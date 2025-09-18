/**
 * Merges menus on mobile into one for better UX
 */
export default function combineMenusOnMobile() {
	const mediaQuery = window.matchMedia( '(max-width: 54rem)' );
	const topMenu = document.getElementById( 'top-navigation' );
	const primaryMenu = document.getElementById( 'primary-menu' );

	// helper function to add and remove list items
	function addAndRemoveMenuItems( newDiv, topMenuUl, topMenuItems, className ) {
		if ( mediaQuery.matches ) {
			//combine menus
			for ( let i = 0; i < topMenuItems.length; i++ ) {
				topMenuItems[ i ].classList.add( className );
				newDiv.appendChild( topMenuItems[ i ] );
			}
			primaryMenu.append( newDiv );
			//hide secondary menu
			topMenu.style.display = 'none';
		} else {
			const allMovedItems = primaryMenu.querySelectorAll( `.${ className }` );
			const allMovedItemsWrappers =
				document.getElementsByClassName( 'moved-items' );
			const movedItems = primaryMenu.querySelectorAll( '.moved-item' );
			const movedSocialItems =
				primaryMenu.querySelectorAll( '.moved-item-social' );
			//show secondary menu
			topMenu.style.display = 'flex';
			//split menus
			for ( let i = 0; i < allMovedItems.length; i++ ) {
				if ( className == 'moved-item' ) {
					topMenuUl.append( movedItems[ i ] );
				} else {
					topMenuUl.append( movedSocialItems[ i ] );
				}
			}
			// clean up
			if ( allMovedItemsWrappers.length > 0 ) {
				for ( let i = 0; i < allMovedItemsWrappers.length; i++ ) {
					allMovedItemsWrappers[ i ].remove();
				}
			}
		}
	}

	function configureMenus() {
		if ( ! topMenu ) {
			return;
		}
		const topMenuUl = topMenu.getElementsByTagName( 'ul' )[ 0 ];
		const topMenuItems = topMenuUl.querySelectorAll( ':scope > li' );
		const socialMenuUl = document.getElementsByClassName(
			'header-social-icons'
		)[ 0 ];
		const socialMenuItems = document.querySelectorAll(
			'.header-social-icons li'
		);
		// construct the wrapper div that we will append the moved items to
		const newDiv = document.createElement( 'ul' );
		newDiv.classList.add( 'moved-items' );

		// construct the wrapper div that we will append the moved items to
		const newDiv2 = document.createElement( 'ul' );
		newDiv2.classList.add( 'moved-items' );

		addAndRemoveMenuItems( newDiv, topMenuUl, topMenuItems, 'moved-item' );
		addAndRemoveMenuItems(
			newDiv2,
			socialMenuUl,
			socialMenuItems,
			'moved-item-social'
		);
	}

	document.addEventListener( 'DOMContentLoaded', configureMenus );
	// this is better than the resize listener, as it executes only on the specific viewport width
	mediaQuery.addEventListener( 'change', function() {
		configureMenus();
	} );
}
