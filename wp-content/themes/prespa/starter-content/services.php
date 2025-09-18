<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

$default_page_content = '
<!-- wp:pattern {"slug":"prespa/six-services"} /-->
<!-- wp:pattern {"slug":"prespa/why-choose-us"} /-->
';

return array(
	'post_type'    => 'page',
	'post_title'   => _x( 'Services', 'Theme starter content', 'prespa' ),
	'post_content' => $default_page_content,
);
