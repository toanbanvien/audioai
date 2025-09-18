<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

$default_page_content = '
<!-- wp:pattern {"slug":"prespa/about-page"} /-->
<!-- wp:pattern {"slug":"prespa/team-members"} /-->
';

return array(
	'post_type'    => 'page',
	'post_title'   => _x( 'About', 'Theme starter content', 'prespa' ),
	'post_content' => $default_page_content,
);
