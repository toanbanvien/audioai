/**
 * This is the main Woocommerce js source file. It is used for theme development.
 * The theme is configured to use Webpack to output a single minified js file for production
 * The actual compiled js file is located in build/js folder
 *
 * @param $
 */
( function( $ ) {
	/**
	 * Wrap wc buttons in span tags,
	 * add wp-element-button class and add data attribute to the title of the link
	 * We need this for styling purposes
	 */

	// Adjust added to cart behavior for the homepage pattern for better ui
	$( 'body' ).on( 'wc_fragments_loaded added_to_cart', function() {
		$( document ).ajaxSuccess( function( event, xhr, settings ) {
			if ( settings.url.indexOf( '?wc-ajax=add_to_cart' ) !== -1 ) {
				$( this.activeElement ).hide();
				$( this.activeElement )
					.next()
					.addClass( 'add_to_cart_button wp-element-button' );
			}
		} );
	} );

	// Update mini cart when added from Gutenberg
	document.body.addEventListener( 'wc-blocks_added_to_cart', () => {
		$( document.body ).trigger( 'wc_fragment_refresh' );
	} );

	// Unload Mini Cart hover preview
	function unloadCartPreview() {
		this.className = this.className.replace( ' focus', '' );
	}
	if ( document.getElementsByClassName( 'site-header-cart' ).length > 0 ) {
		document
			.getElementsByClassName( 'site-header-cart' )[ 0 ]
			.addEventListener( 'mouseleave', unloadCartPreview );
	}

	// eslint-disable-next-line no-undef
}( jQuery ) );
