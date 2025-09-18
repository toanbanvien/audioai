<?php
/**
 * Title: Featured Post
 * Slug: prespa/featured-post
 * Categories: prespa
 */
?>

<!-- wp:group {"style":{"spacing":{"blockGap":"0","padding":{"top":"var:preset|spacing|medium","bottom":"var:preset|spacing|medium"}}},"className":"featured-post-pattern","layout":{"type":"constrained"}} -->
<div class="wp-block-group featured-post-pattern" style="padding-top:var(--wp--preset--spacing--medium);padding-bottom:var(--wp--preset--spacing--medium)"><!-- wp:heading {"textAlign":"center","style":{"spacing":{"padding":{"bottom":"var:preset|spacing|small"}}},"className":"p-animation-text-moveUp"} -->
<h2 class="wp-block-heading has-text-align-center p-animation-text-moveUp" style="padding-bottom:var(--wp--preset--spacing--small)">Latest from the Blog</h2>
<!-- /wp:heading -->

<!-- wp:spacer {"height":"8px"} -->
<div style="height:8px" aria-hidden="true" class="wp-block-spacer"></div>
<!-- /wp:spacer -->

<!-- wp:columns {"align":"wide","style":{"spacing":{"blockGap":{"top":"var:preset|spacing|large","left":"var:preset|spacing|large"}}},"className":"p-animation-down-up"} -->
<div class="wp-block-columns alignwide p-animation-down-up"><!-- wp:column -->
<div class="wp-block-column"><!-- wp:image {"id":2006,"sizeSlug":"full","linkDestination":"none"} -->
<figure class="wp-block-image size-full"><img src="<?php echo esc_url( get_template_directory_uri() ); ?>/assets/img/patterns/beach-landscape.jpg" alt="" class="wp-image-2006"/></figure>
<!-- /wp:image --></div>
<!-- /wp:column -->

<!-- wp:column {"verticalAlignment":"center"} -->
<div class="wp-block-column is-vertically-aligned-center"><!-- wp:paragraph {"className":"top-meta"} -->
<p class="top-meta"><a href="#">long read</a></p>
<!-- /wp:paragraph -->

<!-- wp:heading {"className":"wp-block-heading p-underline-animation"} -->
<h2 class="wp-block-heading p-underline-animation"><a href="#">Finding Beauty in Negative Spaces</a></h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Getting into colleges and universities in America is now the rat race to end all rat races, certainly among the middle classes.</p>
<!-- /wp:paragraph --></div>
<!-- /wp:column --></div>
<!-- /wp:columns -->

<!-- wp:spacer {"height":"32px"} -->
<div style="height:32px" aria-hidden="true" class="wp-block-spacer"></div>
<!-- /wp:spacer --></div>
<!-- /wp:group -->
