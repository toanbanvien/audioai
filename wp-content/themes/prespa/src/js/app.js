/**
 * This is the main js source file. It is used for theme development.
 * The theme is configured to use Webpack to output a single minified js file for production
 * The actual compiled js file is located in build/js folder
 */
import combineMenusOnMobile from './components/combine-menus.js';
import navigation from './components/navigation.js';
import themeOptions from './settings/theme-options.js';
import masonryLayout from './components/masonry.js';
import { wrapButtons } from './components/buttons.js';

( function() {
	navigation();
	themeOptions();
	combineMenusOnMobile();
	masonryLayout();
	wrapButtons();
}() );
