/**
 * File masonry.js.
 *
 * Handles masonry layout implementation
 *
 * @link https://codepen.io/Mathiew82/pen/qzoKOK
 *
 * @param {string} container
 * @param {string} items
 * @param {number} columns
 */
const fecthMasonry = function( container, items, columns ) {
	const containerElement =
		document.getElementsByClassName( container )[ 0 ] || '';
	if ( ! containerElement ) {
		return;
	}
	const wrapperElement = containerElement.parentNode;
	const masonryElements = document.querySelectorAll( '.' + items );
	containerElement.parentNode.removeChild( containerElement );
	const newElement = document.createElement( 'div' );
	newElement.setAttribute( 'id', container );
	newElement.classList.add( 'masonry-layout', 'columns-' + columns );
	wrapperElement.appendChild( newElement );
	let countColumn = 1;
	for ( let i = 1; i <= columns; i++ ) {
		const newColumn = document.createElement( 'div' );
		newColumn.classList.add( 'masonry-column-' + i );
		newElement.appendChild( newColumn );
	}
	for ( let i = 0; i < masonryElements.length; i++ ) {
		const col = document.querySelector(
			'#' + container + ' > .masonry-column-' + countColumn
		);
		col.appendChild( masonryElements[ i ] );
		countColumn = countColumn < columns ? countColumn + 1 : 1;
	}
};

export default function masonryLayout() {
	// eslint-disable-next-line no-undef, camelcase, curly
	if ( ! prespa_customizer_object.has_masonry_layout ) return;
	// eslint-disable-next-line camelcase, no-undef
	fecthMasonry( 'prespa-post-inner', 'post', +prespa_customizer_object.column );
}
