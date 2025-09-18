import { debounce } from '../components/helpers.js';
import { fixedMenu, stickyHeader } from '../components/sticky-header.js';
import {
	postLoopAnimation,
	articlesInViewport,
	blocksAnimation,
	typingAnimation,
	blocksInViewport,
} from '../components/animation.js';
import { darkMode } from '../components/dark-mode.js';
import fixedItems, { backToTop } from '../components/fixed-items.js';
import { runStatsAnimation, featuresDarkMode } from '../components/patterns';
import { spinner } from '../general/spinner.js';

export default function themeOptions() {
	window.addEventListener( 'scroll', debounce( fixedMenu, 10 ) );
	window.addEventListener( 'scroll', debounce( postLoopAnimation, 15 ) );
	window.addEventListener( 'scroll', debounce( blocksAnimation, 15 ) );
	window.addEventListener( 'scroll', debounce( fixedItems, 100 ) );
	window.addEventListener( 'scroll', debounce( runStatsAnimation, 10 ) );
	stickyHeader();
	darkMode();
	backToTop();
	featuresDarkMode();
	blocksInViewport();
	articlesInViewport();
	typingAnimation();
	spinner();
}
