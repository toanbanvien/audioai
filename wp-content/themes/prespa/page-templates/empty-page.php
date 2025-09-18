<?php

/**
 * Template Name: Empty Page
 *
 * This is the template that displays empty page (without page title or featured image).
 * Useful when using the Gutenberg editor to provide more customization options. 
 * @link https://developer.wordpress.org/themes/basics/template-hierarchy/
 *
 * @package prespa
 */

get_header();
?>
<!--Site wrapper-->
<div class="site-wrapper">
    <main id="primary" class="site-main">

        <?php
        while ( have_posts() ) :
            the_post();

            get_template_part( 'template-parts/content', 'empty' );

            // If comments are open or we have at least one comment, load up the comment template.
            if ( comments_open() || get_comments_number() ) :
                comments_template();
            endif;

        endwhile; // End of the loop.
        ?>

    </main><!-- #main -->
</div>

<?php
get_footer();
