export default function fixedItems() {
	const scrollCart = document.getElementById( 'scroll-cart' );
	const backToTopBtn =
		document.getElementsByClassName( 'back-to-top' )[ 0 ] || '';
	if (
		document.body.scrollTop > 100 ||
		document.documentElement.scrollTop > 100
	) {
		if ( scrollCart ) {
			scrollCart.style.display = 'block';
		}
		if ( backToTopBtn ) {
			backToTopBtn.style.display = 'block';
		}
	} else {
		if ( scrollCart ) {
			scrollCart.style.display = 'none';
		}
		if ( backToTopBtn ) {
			backToTopBtn.style.display = 'none';
		}
	}
}

export function backToTop() {
	const backToTopBtn = document.getElementsByClassName( 'back-to-top' )[ 0 ];
	// Smooth scroll to top when back-to-top button is pressed
	if ( backToTopBtn ) {
		backToTopBtn.addEventListener( 'click', function() {
			window.scrollTo( {
				top: 0,
				behavior: 'smooth',
			} );
		} );
	}
}
