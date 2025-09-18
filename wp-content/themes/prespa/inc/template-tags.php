<?php
/**
 * Custom template tags for this theme
 *
 * Eventually, some of the functionality here could be replaced by core features.
 *
 * @package Prespa
 */

if ( ! function_exists( 'prespa_posted_on' ) ) :
	/**
	 * Prints HTML with meta information for the current post-date/time.
	 */
	function prespa_posted_on( $show_post_published_date ) {

		if ( $show_post_published_date ) {
			$time_string = '<time class="entry-date published updated" datetime="%1$s">%2$s</time>';
			if ( get_the_time( 'U' ) !== get_the_modified_time( 'U' ) ) {
				$time_string = '<time class="entry-date published" datetime="%1$s">%2$s</time><time class="updated" datetime="%3$s">%4$s</time>';
			}

			$time_string = sprintf(
				$time_string,
				esc_attr( get_the_date( DATE_W3C ) ),
				esc_html( get_the_date() ),
				esc_attr( get_the_modified_date( DATE_W3C ) ),
				esc_html( get_the_modified_date() )
			);

			$posted_on = 
				wp_kses( prespa_get_svg( 'calendar' ), prespa_get_kses_extended_ruleset() ) .
				'<a href="' . esc_url( get_permalink() ) . '" rel="bookmark"' . prespa_schema_microdata( 'time', 0 ) . '>' . $time_string . '</a>';

			echo '<span class="posted-on"> ' . $posted_on . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}
endif;

if ( ! function_exists( 'prespa_posted_by' ) ) :
	/**
	 * Prints HTML with meta information for the current author.
	 */
	function prespa_posted_by( $show_post_author ) {
		if ( $show_post_author ) {
			$byline = sprintf(
				'<span class="author vcard"' . prespa_schema_microdata( 'author', 0 ) . '><a href="' . esc_url( get_author_posts_url( get_the_author_meta( 'ID' ) ) ) . '">' . '<span class="author-avatar" >' . get_avatar( get_the_author_meta( 'ID' ) ) . '</span>' . esc_html( get_the_author() ) . '</a></span>'
			);

			echo '<span class="byline"> ' . $byline . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}
endif;

  /**
  * Print edit button for logged-in editors
  */
if ( ! function_exists( 'prespa_edit_link' ) ) :

	function prespa_edit_link() {

		edit_post_link(
			sprintf(
				wp_kses(
					/* translators: %s: Name of current post. Only visible to screen readers */
					__( 'Edit <span class="screen-reader-text">%s</span>', 'prespa' ),
					array(
						'span' => array(
							'class' => array(),
						),
					)
				),
				wp_kses_post( get_the_title() )
			),
			'<span class="edit-link">',
			'</span>'
		);

	}
endif;

if ( ! function_exists( 'prespa_posted_in' ) ) :
	function prespa_posted_in( $display_category_list, $icon = '' ) {
		if ( $display_category_list && 'post' === get_post_type() ) {
			$icon = $icon ? wp_kses( prespa_get_svg( 'folder' ), prespa_get_kses_extended_ruleset() ) : '';
			/* translators: used between list items, there is a space after the comma */
			$categories_list = $icon ? get_the_category_list( esc_html( ', ' ) ) : get_the_category_list( esc_html( ' ' ) );
			if ( ( $categories_list ) ) {
				/* translators: 1: list of categories. */
				echo $icon ? '' : '<div class="top-meta">';
				printf( '<span class="cat-links"> %s' . $categories_list . '</span>', $icon ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo $icon ? '' : '</div>';
			}
		}
	}
endif;

if ( ! function_exists( 'prespa_tagged_in' ) ) :
	function prespa_tagged_in( $display_tag_list ) {
		// Hide category and tag text for pages.
		if ( 'post' === get_post_type() ) {
			/* translators: used between list items, there is a space after the comma */
			$tags_list = get_the_tag_list( '', esc_html_x( ', ', 'list item separator', 'prespa' ) );
			if ( ( $tags_list && $display_tag_list ) ) {
				/* translators: 1: list of tags. */
				printf( '<span class="tag-links"' . prespa_schema_microdata( 'tags', 0 ) . '> ' . wp_kses( prespa_get_svg( 'tag' ), prespa_get_kses_extended_ruleset() ) . $tags_list . '</span>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		}
	}
endif;

if ( ! function_exists( 'prespa_display_comments' ) ) :
	function prespa_display_comments( $display_comments ) {
		if ( $display_comments ) {
			if ( ! is_single() && ! post_password_required() && ( comments_open() || get_comments_number() ) ) {
				echo '<span class="comments-link">' . wp_kses( prespa_get_svg( 'comment' ), prespa_get_kses_extended_ruleset() );
				comments_popup_link(
					sprintf(
						wp_kses(
							/* translators: %s: post title */
							__( 'Leave a Comment<span class="screen-reader-text"> on %s</span>', 'prespa' ),
							array(
								'span' => array(
									'class' => array(),
								),
							)
						),
						wp_kses_post( get_the_title() )
					)
				);
				echo '</span>';
			}
		}
	}
endif;

// Time to read post
if ( ! function_exists( 'prespa_show_time_to_read' ) ) :
	function prespa_show_time_to_read( $time_to_read ) {
		if ( 'post' == get_post_type() && $time_to_read ) {

			global $post;
			$words_per_minute = 225;
			$words_per_second = $words_per_minute / 60;

			// Count the words in the content.
			$word_count = $word_count = count( preg_split('/\s+/u', strip_tags( $post->post_content ), -1, PREG_SPLIT_NO_EMPTY ) );

			// How many seconds (total)?
			$seconds_total = floor( $word_count / $words_per_second );

			printf( '<span class="screen-reader-text">' . __( 'Post read time', 'prespa' ) . '</span><span class="time-read-links"> ' . wp_kses( prespa_get_svg( 'clock' ), prespa_get_kses_extended_ruleset() ) . '<span class="time-read">' . wp_kses_post( prespa_convert_read_time( $seconds_total ) . '</span></span>' ) );
		}
	}
endif;

if ( ! function_exists( 'prespa_entry_header' ) ) :
	/**
	 * Prints HTML with meta information before the post content
	 */
	function prespa_entry_header() {
		$post_meta_list = prespa_get_post_meta_before_post_content();

		$date            = in_array( 'show_post_date', prespa_get_post_meta_before_post_content(), true );
		$author          = in_array( 'show_post_author', prespa_get_post_meta_before_post_content(), true );
		$categories_list = in_array( 'show_post_categories', prespa_get_post_meta_before_post_content(), true );
		$tags_list       = in_array( 'show_post_tags', prespa_get_post_meta_before_post_content(), true );
		$comments        = in_array( 'show_post_comments', prespa_get_post_meta_before_post_content(), true );
		$time_to_read    = in_array( 'show_time_to_read', prespa_get_post_meta_before_post_content(), true );

		for ( $i = 0; $i < count( $post_meta_list ); $i++ ) :

			switch ( $post_meta_list[ $i ] ) :
				case 'show_post_date':
					prespa_posted_on( $date );
					break;
				case 'show_post_author':
					prespa_posted_by( $author );
					break;
				case 'show_post_categories':
					prespa_posted_in( $categories_list, true );
					break;
				case 'show_post_tags':
					prespa_tagged_in( $tags_list );
					break;
				case 'show_post_comments':
					prespa_display_comments( $comments );
					break;
				case 'show_time_to_read':
					prespa_show_time_to_read( $time_to_read );
					break;
			endswitch;

		endfor;

	}
endif;

if ( ! function_exists( 'prespa_entry_footer' ) ) :
	/**
	 * Prints HTML with meta information after post content
	 */
	function prespa_entry_footer() {
		$post_meta_list = prespa_get_post_meta_after_post_content();

		$date            = in_array( 'show_post_date', prespa_get_post_meta_after_post_content(), true );
		$author          = in_array( 'show_post_author', prespa_get_post_meta_after_post_content(), true );
		$categories_list = in_array( 'show_post_categories', prespa_get_post_meta_after_post_content(), true );
		$tags_list       = in_array( 'show_post_tags', prespa_get_post_meta_after_post_content(), true );
		$comments        = in_array( 'show_post_comments', prespa_get_post_meta_after_post_content(), true );
		$time_to_read    = in_array( 'show_time_to_read', prespa_get_post_meta_after_post_content(), true );

		for ( $i = 0; $i < count( $post_meta_list ); $i++ ) :

			switch ( $post_meta_list[ $i ] ) :
				case 'show_post_date':
					prespa_posted_on( $date );
					break;
				case 'show_post_author':
					prespa_posted_by( $author );
					break;
				case 'show_post_categories':
					prespa_posted_in( $categories_list, true );
					break;
				case 'show_post_tags':
					prespa_tagged_in( $tags_list );
					break;
				case 'show_post_comments':
					prespa_display_comments( $comments );
					break;
				case 'show_time_to_read':
					prespa_show_time_to_read( $time_to_read );
					break;
			endswitch;

		endfor;
	}
endif;

if ( ! function_exists( 'prespa_post_thumbnail' ) ) :
	/**
	 * Displays an optional post thumbnail.
	 *
	 * Wraps the post thumbnail in an anchor element on index views, or a div
	 * element when on single views.
	 */
	function prespa_post_thumbnail( $size = '' ) {
		if ( post_password_required() || is_attachment() ) {
			return;
		}

		if ( is_singular() ) :
			?>

			<div class="post-thumbnail" <?php prespa_schema_microdata( 'image' ); ?>>
				<?php the_post_thumbnail(); ?>
			</div><!-- .post-thumbnail -->

			<?php
		else :

			if ( has_post_thumbnail() ) :
				?>
			<a class="post-thumbnail" <?php prespa_schema_microdata( 'image' ); ?> href="<?php the_permalink(); ?>" aria-hidden="true" tabindex="-1">
				<figure>
					<?php
						the_post_thumbnail(
							$size,
							array(
								'alt' => the_title_attribute(
									array(
										'echo' => false,
									)
								),
							)
						);
					?>
				</figure>
			</a> 

				<?php
			else :

				if ( ! get_theme_mod( 'get_post_thumbnail_from_content', true ) ) {
					return;
				}

				$url = prespa_get_featured_image_from_content();

				// If an image is found, display it
				if ( $url ) {
					?>
					<a class="post-thumbnail" <?php prespa_schema_microdata( 'image' ); ?> href="<?php the_permalink(); ?>" aria-hidden="true" tabindex="-1">
						<figure>
						<?php
						echo '<img src="' . esc_url( $url ) . '" alt="' . esc_attr( get_the_title() ) . '" />';
						?>
						</figure>
					</a>
					<?php
				}
			endif; // End has_post_thumbnail

		endif; // End is_singular().
	}
endif;

if ( ! function_exists( 'prespa_default_post_thumbnail' ) ) :
	/**
	 * Displays an optional default post thumbnail.
	 *
	 * It can be used on featured categories, you missed section as well as in the query loop
	 */
	function prespa_default_post_thumbnail() {
		printf( '<span class="screen-reader-text">%s</span>', esc_html__( 'Image Placeholder', 'prespa' ) );
		echo wp_kses( prespa_get_svg( 'fallback-svg' ), prespa_get_kses_extended_ruleset() );
	}
endif;

if ( ! function_exists( 'prespa_get_featured_image_from_content' ) ) :
	/**
	 * Get the first image from the post content to use as a fallback featured image
	 */
	function prespa_get_featured_image_from_content() {
		global $post;
		$post_content = $post->post_content;
		$image_url    = '';
		preg_match( '/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $post_content, $matches );
		if ( $matches && isset( $matches[1] ) ) {
			$image_url = esc_url( $matches[1] );
			$image_id  = attachment_url_to_postid( $image_url );
			if ( $image_id ) {
				// Get the 'medium' size image URL
				$image_url = wp_get_attachment_image_src( $image_id, 'medium_large' )[0];
			}
		}
		return $image_url;
	}
endif;

/**
 * Adds a Sub Nav Toggle Button to the Mobile Menu.
 *
 * @param stdClass $args  An object of wp_nav_menu() arguments.
 * @param WP_Post  $item  Menu item data object.
 * @param int      $depth Depth of menu item. Used for padding.
 * @return stdClass An object of wp_nav_menu() arguments.
 */
function prespa_add_sub_toggles_to_main_menu( $args, $item, $depth ) {
	// Add sub menu toggles to the Expanded Menu with toggles.
	if ( isset( $args->show_toggles ) ) {

		$args->after = '';

		// Add a toggle to items with children.
		if ( in_array( 'menu-item-has-children', $item->classes, true ) || in_array( 'page_item_has_children', $item->classes, true ) ) {

			// Add the sub menu toggle.
			$args->after .= '<button class="menu-toggle sub-menu-toggle" aria-expanded="false">' . '<i class="arrow-down"></i>' . '<span class="screen-reader-text">' . __( 'Show sub menu', 'prespa' ) . '</span></button>';

		}
	}

	return $args;

}

add_filter( 'nav_menu_item_args', 'prespa_add_sub_toggles_to_main_menu', 10, 3 );

/* Primary Menu */

if ( ! function_exists( 'prespa_primary_menu_dark_mode_markup' ) ) :
	function prespa_primary_menu_dark_mode_markup() {
		$isDarkMode = get_theme_mod( 'enable_dark_mode', 1 ) == 1;

		if ( $isDarkMode ) :
			?>
			<li class="dark-mode-menu-item">
			<?php prespa_dark_mode_button_markup(); ?>
			</li>
			<?php
		endif;
	}
endif;

/**
 * Top Bar Menu
 */

function prespa_location_icon() {
	$location = get_theme_mod( 'location', 'My Street 12' );

	if ( $location ) :
		?>
		<li class="location">
			<a href ="#">
			<?php
				echo sprintf(
				/* translators: %s: location */
					'%s&nbsp;' . esc_html__( '%s', 'prespa' ),
					wp_kses( prespa_get_svg( 'map-pin' ), prespa_get_kses_extended_ruleset() ),
					$location
				);
			?>
			</a>
		</li>
		 <?php
	 endif;
}

function prespa_phone_icon() {
	$phone = get_theme_mod( 'phone_control', '00123456789' );

	if ( $phone ) :
		?>
		<li class="phone">
			<a href="tel:<?php echo esc_attr( $phone ); ?>">
				<?php echo wp_kses( prespa_get_svg( 'phone' ), prespa_get_kses_extended_ruleset() ) . '&nbsp;' . esc_html( $phone ); ?>
			</a>
		</li>
		 <?php
	 endif;
}

function prespa_mail_icon() {
	$mail = get_theme_mod( 'mail_control', 'email@example.com' );

	if ( $mail ) :
		?>
		<li class="mail">
			<a href="mailto:<?php echo esc_attr( $mail ); ?>">
				<?php echo wp_kses( prespa_get_svg( 'mail' ), prespa_get_kses_extended_ruleset() ) . '&nbsp;' . esc_html( $mail ); ?>
			</a>
		</li>
		 <?php
	 endif;
}

function prespa_search_icon() {
	$has_search = get_theme_mod( 'has_search', 1 );
	if ( $has_search ) :
		prespa_add_search_box();
	 endif;
}

function prespa_wc_icons() {
	if ( prespa_topmenu_has_wc_items() ) :
		do_action( 'prespa_fixed_menu_hook' );
	endif;
}

function prespa_social_icons_header() {
	$social_urls = get_theme_mod( 'prespa_social_icons_setting', 'https://facebook.com/#,https://instagram.com/#,https://twitter.com/#,https://linkedin.com/#' );
	echo '<ul class="header-social-icons">';
	prespa_social_icons( $social_urls );
	echo '</ul>';
}

function prespa_social_icons_footer() {
	if ( ! get_theme_mod( 'show_footer_social_icons', true ) ) {
		return;
	}
	$social_urls = get_theme_mod( 'prespa_social_icons_footer', 'https://facebook.com/#,https://instagram.com/#,https://twitter.com/#,https://linkedin.com/#' );
	echo '<ul class="social-icons">';
	prespa_social_icons( $social_urls );
	echo '</ul>';
}

function prespa_social_icons( $social_urls ) {
	$social_urls_list = explode( ',', $social_urls );

	foreach ( $social_urls_list as $url ) :
		if ( empty( $url ) ) {
			continue;
		}
		// get the domain name from url
		$domain_name = str_ireplace( 'www.', '', parse_url( $url, PHP_URL_HOST ) );
		$domain      = explode( '.', $domain_name )[0];
		/* translators: %s: domain name, i.e. facebook. */

		if ( strtolower( $domain_name ) === 't.me' ) {
			$domain = 'telegram';
		}
		?>
		<li class="social-icon">
			<a href="<?php echo esc_url( $url ); ?>" aria-label="<?php printf( esc_attr__( '%s', 'prespa' ), $domain ); ?>">
		<?php
		switch ( $domain ) :
			case 'facebook':
				echo wp_kses( prespa_get_svg( 'facebook' ), prespa_get_kses_extended_ruleset() );
				break;
			case 'instagram':
				echo wp_kses( prespa_get_svg( 'instagram' ), prespa_get_kses_extended_ruleset() );
				break;
			case 'twitter':
				echo wp_kses( prespa_get_svg( 'twitter' ), prespa_get_kses_extended_ruleset() );
				break;
			case 'linkedin':
				echo wp_kses( prespa_get_svg( 'linkedin' ), prespa_get_kses_extended_ruleset() );
				break;
			case 'youtube':
				echo wp_kses( prespa_get_svg( 'youtube' ), prespa_get_kses_extended_ruleset() );
				break;
			case 'pinterest':
				echo wp_kses( prespa_get_svg( 'pinterest' ), prespa_get_kses_extended_ruleset() );
				break;
			case 'wordpress': // phpcs:ignore WordPress.WP.CapitalPDangit.Misspelled
				echo wp_kses( prespa_get_svg( 'wordpress' ), prespa_get_kses_extended_ruleset() ); // phpcs:ignore WordPress.WP.CapitalPDangit.Misspelled
				break;
			case 'behance':
				echo wp_kses( prespa_get_svg( 'behance' ), prespa_get_kses_extended_ruleset() );
				break;
			case 'dribble':
				echo wp_kses( prespa_get_svg( 'dribble' ), prespa_get_kses_extended_ruleset() );
				break;
			case 'flickr':
				echo wp_kses( prespa_get_svg( 'flickr' ), prespa_get_kses_extended_ruleset() );
				break;
			case 'github':
				echo wp_kses( prespa_get_svg( 'github' ), prespa_get_kses_extended_ruleset() );
				break;
			case 'soundcloud':
				echo wp_kses( prespa_get_svg( 'soundcloud' ), prespa_get_kses_extended_ruleset() );
				break;
			case 'snapchat':
				echo wp_kses( prespa_get_svg( 'snapchat' ), prespa_get_kses_extended_ruleset() );
				break;
			case 'telegram':
				echo wp_kses( prespa_get_svg( 'telegram' ), prespa_get_kses_extended_ruleset() );
				break;
			case 'tiktok':
				echo wp_kses( prespa_get_svg( 'tiktok' ), prespa_get_kses_extended_ruleset() );
				break;
			case 'twitch':
				echo wp_kses( prespa_get_svg( 'twitch' ), prespa_get_kses_extended_ruleset() );
				break;
			case 'tumblr':
				echo wp_kses( prespa_get_svg( 'tumblr' ), prespa_get_kses_extended_ruleset() );
				break;
			default:
				echo wp_kses( prespa_get_svg( 'globe' ), prespa_get_kses_extended_ruleset() );
				break;

		endswitch;
		?>
			</a>
		</li>
		<?php
	endforeach;
}

function prespa_secondary_menu_markup() {
	prespa_location_icon();

	prespa_phone_icon();

	prespa_mail_icon();

	prespa_wc_icons();

}

function prespa_call_to_action_markup() {
	$button      = prespa_customizer_values( 'header_button_text' );
	$button_link = get_theme_mod( 'header_button_link', '#' );
	if ( ! $button ) {
		return;
	}
	?>
	<li class="wp-block-button call-to-action">
		<a class="wp-block-button__link" href="<?php echo esc_url( $button_link ); ?>"><?php echo esc_html( $button ); ?></a>
	</li>
	<?php
}

/**
 * Dark Mode Toggle Button Html markup
 **/

function prespa_dark_mode_button_markup() {
	?>
	<button aria-label="Click to toggle dark mode" class="dark-mode-widget">
	   <?php echo wp_kses( prespa_get_svg( 'sun' ), prespa_get_kses_extended_ruleset() ); ?>
	   <?php echo wp_kses( prespa_get_svg( 'moon' ), prespa_get_kses_extended_ruleset() ); ?>
	</button>
	<?php
	prespa_dark_mode_loader();
}

// Print the menu icons

function prespa_add_menu_icons( $items, $args ) {

	// if primary menu
	if ( $args->theme_location == 'menu-1' ) {
		ob_start();
		prespa_search_icon();
		prespa_primary_menu_dark_mode_markup();
		prespa_call_to_action_markup();
		$items .= ob_get_clean();
		return $items;
	} // if top menu
	elseif ( $args->theme_location == 'menu-2' ) {
		ob_start();
		prespa_secondary_menu_markup();
		$secondary_menu_markup = ob_get_clean();

		ob_start();
		$items = $secondary_menu_markup . $items . ob_get_clean();
		return $items;
	} else {
		return $items;
	}
}

add_filter( 'wp_nav_menu_items', 'prespa_add_menu_icons', 1, 20 );

function prespa_primary_menu_object( $items, $args ) {
		ob_start();
		prespa_search_icon();
		prespa_primary_menu_dark_mode_markup();
		prespa_call_to_action_markup();
		$items .= ob_get_clean();
		return $items;
}

add_filter( 'wp_list_pages', 'prespa_primary_menu_object', 10, 2 );

/* Add search list item to top menu bar */
function prespa_add_search_box() {
	?>
	<li class="search-icon">
		<a href="#search-open" aria-label="<?php _e( 'search', 'prespa' ); ?>">
			<?php echo wp_kses( prespa_get_svg( 'search' ), prespa_get_kses_extended_ruleset() ); ?>
		</a>
	</li>
	<?php
}

/* Full Screen Search */
function prespa_full_screen_search() {
	?>
	<div id="search-open">
		<div class="search-box-wrap">
			<div class="header-search-form">
				<?php
				get_search_form();
				?>
			</div>
		</div>
		<a href="#search-close" class="close">
			<button class="close-btn" tabindex="-1" aria-label="<?php _e( 'close', 'prespa' ); ?>">
			<?php
			echo wp_kses( prespa_get_svg( 'close' ), prespa_get_kses_extended_ruleset() );
			?>
	</button>
		</a>
	</div>
	<a href="#search-close" class="search-close"></a>
	<?php
}

function prespa_footer_default_theme_credits() {
	esc_html_e( 'Designed by', 'prespa' );
	?>
	<a href="<?php echo esc_url( __( 'https://nasiothemes.com/', 'prespa' ) ); ?>" class="imprint">
		<?php esc_html_e( 'Nasio Themes', 'prespa' ); ?>
	</a>
	<span class="sep"> || </span>
	<?php
	/* translators: %s: CMS name, i.e. WordPress. */
	esc_html_e( 'Powered by', 'prespa' );
	?>
	<a href="<?php echo esc_url( __( 'https://wordpress.org/', 'prespa' ) ); ?>" class="imprint">
		<?php esc_html_e( 'WordPress', 'prespa' ); ?>
	</a>
	<?php
}

/**
 * Back to top
 */
function prespa_back_to_top() {
	if ( ! get_theme_mod( 'has_back_to_top', 1 ) ) {
		return;
	}
	?>
	<button class="back-to-top" aria-label="<?php _e( 'back to top', 'prespa' ); ?>">
		<?php echo wp_kses( prespa_get_svg( 'arrow-double-up' ), prespa_get_kses_extended_ruleset() ); ?>
	</button>
	<?php
}

function prespa_header_image() {
	if ( ! prespa_has_fullwidth_featured_image() || ( ! is_front_page() && is_page_template( 'page-templates/empty-page.php' ) ) ) {
		return;
	}
	$title                     = get_theme_mod( 'prespa_header_title', __( 'JOURNEY OF A THOUSAND MILES BEGINS WITH A SINGLE STEP', 'prespa' ) );
	$description               = get_theme_mod( 'prespa_header_description', __( 'Don\'t let the opportunity pass you by', 'prespa' ) );
	$button                    = get_theme_mod( 'prespa_call_to_action', __( 'Get Started', 'prespa' ) );
	$button_link               = get_theme_mod( 'prespa_banner_link', '#' );
	$btn_hover_animation_class = get_theme_mod( 'prespa_call_to_action_hover_animation', 'p-btn-animation-border-move' );

	if ( has_header_image() && is_front_page() ) : // homepage
		?>

		<div class="header-image-wrapper">
			<?php if ( $title || $description || $button ) : ?> 
			<div class="header-image-container">
				<h1 class="p-scale-change-animation" <?php prespa_schema_microdata( 'entry-title' ); ?>><?php echo esc_html( $title ); ?></h1>
				<p class="p-typewrite-animation"><?php echo esc_html( $description ); ?></p>
				<?php if ( $button || $button_link ) : ?>
				<div class ="btn-wrapper">
					<div class="wp-block-button <?php echo esc_attr( $btn_hover_animation_class ); ?>">
						<a href="<?php echo esc_attr( $button_link ); ?>" class="wp-block-button__link slider-button wp-element-button"><?php echo esc_html( $button ); ?></a>
					</div>
				</div>
				<?php endif; ?>
			</div>
			<?php endif; ?>
		</div>
		<?php elseif ( has_post_thumbnail() && is_singular() ) : ?>
			<div class="header-image-wrapper">
				<div class="header-image-container">
					<?php
						/**
						 * prespa_breadcrumbs_hook
						 *
						 * @since 1.0.0
						 * @hooked prespa_breadcrumbs
						 */
						do_action( 'prespa_breadcrumbs_hook' );
					?>
					<h1 <?php prespa_schema_microdata( 'entry-title' ); ?>><?php echo esc_html( get_the_title( get_the_ID() ) ); ?></h1>
					<?php if ( has_excerpt( get_the_ID() ) ) : ?>
						<p><?php prespa_the_excerpt( 37 ); ?></p>
					<?php endif; ?>
				</div>
			</div>
			<?php
	endif;
}

function prespa_social_posts_share() {
	if ( 'post' !== get_post_type() ) {
		return;
	}
	$social_share = get_theme_mod( 'show_social_share_icons', 1 );

	if ( $social_share ) {
		?>
		<div class="post-share-wrap">
			<span class="post-share">
				<span class="screen-reader-text">
				<?php
				echo esc_html__( 'Share this post on: ', 'prespa' );
				?>
				</span>
				<a aria-label="<?php _e( 'facebook', 'prespa' ); ?>" title="<?php _e( 'Share this post on Facebook', 'prespa' ); ?>" target="_blank" href="
					<?php
						echo esc_url( 'https://www.facebook.com/sharer/sharer.php?u=' . get_the_permalink() );
					?>
					">
					<i class="share-icon">
						<?php
						echo wp_kses( prespa_get_svg( 'facebook' ), prespa_get_kses_extended_ruleset() );
						?>
					</i>
				</a>
				<a aria-label="<?php _e( 'twitter', 'prespa' ); ?>" title="<?php _e( 'Share this post on Twitter', 'prespa' ); ?>" href="
					<?php
					echo esc_url( 'http://twitter.com/intent/tweet?text=Currently reading ' . esc_html( get_the_title() ) . '&url=' . get_the_permalink() );
					?>
					" target="_blank" rel="noopener noreferrer">
					<i class="share-icon">
						<?php
						echo wp_kses( prespa_get_svg( 'twitter' ), prespa_get_kses_extended_ruleset() );
						?>
					</i>
				</a>
				<a target="_blank" title="<?php _e( 'Share this post on Linkedin', 'prespa' ); ?>" href="
					<?php
					echo esc_url( 'http://www.linkedin.com/shareArticle?mini=true&url=' . get_the_permalink() . '&title=' . esc_html( get_the_title() ) . '&source=' . get_bloginfo( 'name' ) );
					?>
					">
					<i class="share-icon">
						<?php
						echo wp_kses( prespa_get_svg( 'linkedin' ), prespa_get_kses_extended_ruleset() );
						?>
					</i>
				</a>
			</span>
		</div>
		<?php
	}

}
