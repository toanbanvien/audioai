<?php
/**
 * Home starter content.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

$default_page_content = '
<!-- wp:pattern {"slug":"prespa/hero-image"} /-->
<!-- wp:pattern {"slug":"prespa/features"} /-->
<!-- wp:pattern {"slug":"prespa/case-studies"} /-->
<!-- wp:pattern {"slug":"prespa/services"} /-->
<!-- wp:pattern {"slug":"prespa/stats-counter"} /-->
<!-- wp:pattern {"slug":"prespa/pricing-table"} /-->
<!-- wp:pattern {"slug":"prespa/faq"} /-->
<!-- wp:pattern {"slug":"prespa/testimonials"} /-->
<!-- wp:pattern {"slug":"prespa/contact-us"} /-->
<!-- wp:pattern {"slug":"prespa/team-members"} /-->
<!-- wp:pattern {"slug":"prespa/featured-post"} /-->
';

return array(
	'post_type'    => 'page',
	'post_title'   => _x( 'Home', 'Theme starter content', 'prespa' ),
	'post_content' => $default_page_content,
);