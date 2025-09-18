<?php

/**
 * Define Constants
 */
if ( ! defined( 'PRESPA_PAGE_BASENAME' ) ) {
	define( 'PRESPA_PAGE_BASENAME', 'prespa-doc' );
}
if ( ! defined( 'PRESPA_THEME_DETAILS' ) ) {
	define( 'PRESPA_THEME_DETAILS', 'https://nasiothemes.com/themes/prespa/' );
}
if ( ! defined( 'PRESPA_THEME_LOGO_URL' ) ) {
	define( 'PRESPA_THEME_LOGO_URL', get_template_directory_uri() . '/admin/img/theme-logo.jpg' );
}
if ( ! defined( 'PRESPA_THEME_DEMO' ) ) {
	define( 'PRESPA_THEME_DEMO', 'https://prespa-demo.nasiothemes.com/' );
}
if ( ! defined( 'PRESPA_THEME_OPTIONS' ) ) {
	define( 'PRESPA_THEME_OPTIONS', esc_url ( admin_url( '/customize.php' ) ) );
}
if ( ! defined( 'PRESPA_THEME_FEATURES' ) ) {
	define( 'PRESPA_THEME_FEATURES', 'https://nasiothemes.com/themes/prespa/#features' );
}
if ( ! defined( 'PRESPA_THEME_DOCUMENTATION_URL' ) ) {
	define( 'PRESPA_THEME_DOCUMENTATION_URL', 'https://nasiothemes.com/prespa-theme-documentation/' );
}
if ( ! defined( 'PRESPA_THEME_SUPPORT_FORUM_URL' ) ) {
	define( 'PRESPA_THEME_SUPPORT_FORUM_URL', 'https://wordpress.org/support/theme/prespa/' );
}
if ( ! defined( 'PRESPA_THEME_REVIEW_URL' ) ) {
	define( 'PRESPA_THEME_REVIEW_URL', 'https://wordpress.org/support/theme/prespa/reviews/#new-post' );
}
if ( ! defined( 'PRESPA_THEME_UPGRADE_URL' ) ) {
	define( 'PRESPA_THEME_UPGRADE_URL', 'https://nasiothemes.com/themes/prespa/#choose-plan' );
}
if ( ! defined( 'PRESPA_THEME_DEMO_IMPORT_URL' ) ) {
	define( 'PRESPA_THEME_DEMO_IMPORT_URL', false );
}
/**
 * Specify Hooks/Filters
 */
add_action( 'admin_menu', 'prespa_add_menu' );
/**
 * The admin menu pages
 */
function prespa_add_menu() {
	add_theme_page(
		__( 'Prespa Theme', 'prespa' ),
		__( 'Prespa Theme', 'prespa' ),
		'edit_theme_options',
		PRESPA_PAGE_BASENAME,
		'prespa_settings_page_doc'
	);
}

/*
 * Theme Documentation Page HTML
 *
 * @return echoes output
 */
function prespa_settings_page_doc() {
	// get the settings sections array
	$theme_data = wp_get_theme();
	$parent_theme_data = wp_get_theme()->get('Template');
	?>
	
	<div class="nasiothemes-wrapper">
		<div class="nasiothemes-header">
			<div id="nasiothemes-theme-info">
				<div class="nasiothemes-message-image">
					<img class="nasiothemes-screenshot" 
						src="<?php echo esc_url( PRESPA_THEME_LOGO_URL ); ?>" 
						alt="<?php esc_attr_e( 'Prespa Theme Screenshot', 'prespa' ); ?>" />
				</div><!-- ws fix
				--><p>
						<?php
						echo sprintf(
								/* translators: Theme name and version */
							__( '<span class="theme-name">%1$s Theme</span> <span class="theme-version">(version %2$s)</span>', 'prespa' ),
							esc_html( $theme_data->name ),
							esc_html( $theme_data->version )
						);
						?>
					</p>
					<p class="theme-buttons">
						<a class="button button-primary" href="<?php echo esc_url( PRESPA_THEME_DETAILS ); ?>" rel="noopener" target="_blank">
							<?php esc_html_e( 'Theme Homepage', 'prespa' ); ?>
						</a>
						<a class="button button-primary" href="<?php echo esc_url( PRESPA_THEME_OPTIONS ); ?>" rel="noopener" target="_blank">
							<?php esc_html_e( 'Theme Options', 'prespa' ); ?>
						</a>
						<?php if ( defined( 'PRESPA_THEME_VIDEO_GUIDE' ) ) : ?>
						<a class="button button-primary nasiothemes-button nasiothemes-button-youtube" href="<?php echo esc_url( PRESPA_THEME_VIDEO_GUIDE ); ?>" rel="noopener" target="_blank">
							<span class="dashicons dashicons-youtube"></span>
							<?php esc_html_e( 'Video Guide', 'prespa' ); ?>
						</a>
						<?php endif; ?>
					</p>
			</div><!-- .nasiothemes-header -->
		
		<div class="nasiothemes-documentation">

			<ul class="nasiothemes-doc-columns clearfix">
				<li class="nasiothemes-doc-column nasiothemes-doc-column-1">
					<div class="nasiothemes-doc-column-wrapper">
						<div class="doc-section">
							<h3 class="column-title"><span class="nasiothemes-icon dashicons dashicons-editor-help"></span><span class="nasiothemes-title-text">
							<?php
							esc_html_e( 'Documentation and Support', 'prespa' );
							?>
							</span></h3>
							<div class="nasiothemes-doc-column-text-wrapper">
								<p>
								<?php echo sprintf(
									/* translators: Theme name and link to WordPress.org Support forum for the theme */
									__( 'Please check the theme documentation before using the theme. Support for %1$s Theme is provided in the official WordPress.org community support forum. ', 'prespa' ),
									esc_html( $theme_data->name )
								);?>
								</p>
								<p class="doc-buttons">
									<a class="button button-primary" href="<?php echo esc_url( PRESPA_THEME_DOCUMENTATION_URL );?>" rel="noopener" target="_blank">
									<?php esc_html_e( 'View Prespa Documentation', 'prespa' ); ?>
									</a>
								<?php if ( PRESPA_THEME_SUPPORT_FORUM_URL ) { ?>
									 <a class="button button-secondary" href="<?php echo esc_url( PRESPA_THEME_SUPPORT_FORUM_URL ); ?>" rel="noopener" target="_blank">
									<?php esc_html_e( 'Go to Prespa Support Forum', 'prespa' );
									?>
									</a>
									<?php
								} ?>
								</p>
							</div><!-- .nasiothemes-doc-column-text-wrapper-->
						</div><!-- .doc-section -->
						<div class="doc-section">
							<h3 class="column-title"><span class="nasiothemes-icon dashicons dashicons-editor-help"></span><span class="nasiothemes-title-text">
								<?php esc_html_e( 'FAQ', 'prespa' ); ?></span>
							</h3>
							<div class="nasiothemes-doc-column-text-wrapper">
								<strong><?php esc_html_e( '1. Where are the theme options?', 'prespa' ); ?></strong>
								<p><i><?php esc_html_e( 'Please navigate to Appearance => Customize and customize the theme to taste.', 'prespa' ); ?></i></p>
								<br>
								<strong><?php esc_html_e( '2. Can I add demo content for an easier start with the theme?', 'prespa' ); ?></strong>
								<p><i><?php esc_html_e( 'Yes, in the pro version of the theme, there is a demo import button that does all the heavy lifting for you. You can choose between 12 different demos. If you are using the free version, you can take advantage of the block patterns that are included in this theme.', 'prespa' ); ?></i></p>
								<br>
								<strong><?php esc_html_e( '3. Where are the custom block patterns?', 'prespa' ); ?></strong>
								<p><i><?php esc_html_e( 'In the WordPress admin, create or edit a page, then click on the plus icon in the top left corner of the Gutenberg editor. From there, navigate to the "Patterns" tab and select "Prespa" from the pattern dropdown.', 'prespa' ); ?></i></p>
								<br>
								<strong><?php esc_html_e( '4. Can I make a website that looks exactly like the theme demo?', 'prespa' ); ?></strong>
								<p><i><?php esc_html_e( 'Absolutely! all you need to do is upgrade to Prespa PRO and run the one-click demo import.', 'prespa' ); ?></i></p>
								<br>
								<strong><?php esc_html_e( '5. I have already purchased the premium version. How do I install and activate it?', 'prespa' ); ?></strong>
								<p><i><?php esc_html_e( 'To install the Prespa Pro theme, go to Appearance => Themes => Add new and upload the zip file that you have received upon theme purchase. To activate it, hover over Prespa Pro theme from the theme list on the same page and click "Activate". Finally, you will be asked to enter a license key that you have received by email.', 'prespa' ); ?></i></p>
								<br>
							</div><!-- .nasiothemes-doc-column-text-wrapper-->
						</div><!-- .doc-section -->

                        <div class="doc-section">
							<h3 class="column-title"><span class="nasiothemes-icon dashicons dashicons-awards"></span><span class="nasiothemes-title-text">
							<?php esc_html_e( 'Leave a Review', 'prespa' );?>
							</span></h3>
							<div class="nasiothemes-doc-column-text-wrapper">
								<p><?php esc_html_e( 'If you enjoy using Prespa Theme, please leave a review for it on WordPress.org. It encourages us to continue providing updates and support for it.', 'prespa' );?></p>
								<p class="doc-buttons">
                                    <a class="button button-primary" href="<?php echo esc_url( PRESPA_THEME_REVIEW_URL );?>" rel="noopener" target="_blank">
                                        <?php esc_html_e( 'Write a Review for Prespa', 'prespa' );?>
                                    </a>
                                </p>
							</div><!-- .nasiothemes-doc-column-text-wrapper-->
						</div><!-- .doc-section -->
					</div><!-- .nasiothemes-doc-column-wrapper -->
				</li><!-- .nasiothemes-doc-column --><li class="nasiothemes-doc-column nasiothemes-doc-column-2">
				<div class="nasiothemes-doc-column-wrapper">
						<?php

						if ( PRESPA_THEME_UPGRADE_URL ) {
							?>
						<div class="doc-section">
							<h3 class="column-title"><span class="nasiothemes-icon dashicons dashicons-cart"></span><span class="nasiothemes-title-text">
							<?php echo sprintf(
                                /* translators: Theme name and link to WordPress.org Support forum for the theme */
                                __( 'Upgrade to %1$s Pro', 'prespa' ), esc_html( wp_get_theme($parent_theme_data)->get('Name') ) )
							?>
							</span>
                            </h3>
							<div class="nasiothemes-doc-column-text-wrapper">
								<p>
                                    <?php echo sprintf(
                                        /* translators: Theme name and link to WordPress.org Support forum for the theme */
                                        __( 'If you like the free version of %1$s Theme, you will love the PRO version!', 'prespa' ),
                                        esc_html( $theme_data->name )
                                    );
                                    ?>
								</p>
								<p>
								<?php esc_html_e( 'You will be able to create an even more unique website using the additional functionalities and customization options available in the pro version.', 'prespa' );
								?>
								<br>
								<p class="doc-buttons"><a class="button button-primary" href="
								<?php echo esc_url( PRESPA_THEME_UPGRADE_URL );
								?>
								" rel="noopener" target="_blank">
								<?php esc_html_e( 'Upgrade to Prespa PRO', 'prespa' );
								?>
								</a>
								<?php

								if ( PRESPA_THEME_FEATURES ) {
									?>
									<a class="button button-primary nasiothemes-button nasiothemes-button-youtube" href="
									<?php echo esc_url( PRESPA_THEME_FEATURES );
									?>
									" rel="noopener" target="_blank">
									<?php esc_html_e( 'View Full List of Features', 'prespa' );
									?>
									</a>
									<?php
								}

								?>
								</p>

								<table class="theme-comparison-table">
									<tr>
										<th class="table-feature-title">
											<?php esc_html_e( 'Feature', 'prespa' ); ?>
										</th>
										<th class="table-lite-value">
											<?php esc_html_e( 'Prespa', 'prespa' ); ?>
										</th>
										<th class="table-pro-value">
											<?php esc_html_e( 'Prespa PRO', 'prespa' ); ?>
										</th>
									</tr>
									<tr>
										<td><div class="nasio-tooltip"><span class="dashicons dashicons-editor-help"></span><span class="nasio-tooltiptext">
											<?php esc_html_e( 'Import demo content with predefined posts, pages and images for an easier start with the theme.', 'prespa' ); ?></span></div>
												<?php esc_html_e( 'One Click Demo Import', 'prespa' );?>
										</td>
										<td><span class="dashicons dashicons-minus"></span></td>
										<td><span class="dashicons dashicons-yes-alt"></span> <strong><?php esc_html_e('12 Demo templates','prespa'); ?></strong</td>
									</tr>
									<tr>
										<td><div class="nasio-tooltip"><span class="dashicons dashicons-editor-help"></span><span class="nasio-tooltiptext">
											<?php esc_html_e('Choose between 1000+ Google Fonts and change font size for the main elements on the website directly from the Customizer. No coding skills needed!','prespa'); ?></span></div><?php esc_html_e('Change Fonts and Font Sizes','prespa'); ?>
										</td>
										<td><span class="dashicons dashicons-minus"></span></td>
										<td><span class="dashicons dashicons-yes-alt"></span> <strong><?php esc_html_e('1000+ Google Fonts','prespa'); ?></strong></td>
									</tr>
									<tr>
										<td><div class="nasio-tooltip"><span class="dashicons dashicons-editor-help"></span><span class="nasio-tooltiptext">
											<?php esc_html_e( 'Add ready to use gutenberg block patterns with predefined content for an easier start with the theme. No extra plugins needed!', 'prespa' ); ?></span></div>
											<?php esc_html_e( 'Block Patterns', 'prespa' ); ?>
										</td>
										<td>
											<span class="dashicons dashicons-yes-alt"></span>
											<?php esc_html_e( '15', 'prespa' ); ?>
										</td>
										<td>
											<span class="dashicons dashicons-yes-alt"></span>
											<?php esc_html_e( '63', 'prespa' ); ?>
										</td>
									</tr>
									<tr>
										<td><div class="nasio-tooltip"><span class="dashicons dashicons-editor-help"></span><span class="nasio-tooltiptext">
										<?php esc_html_e( 'Custom sidebar options and content wrap options for the homepage and the post archives.', 'prespa' );
										?>
										</span></div>
										<?php esc_html_e( 'More layout options', 'prespa' );
										?>
										</td>
										<td><span class="dashicons dashicons-minus"></span></td>
										<td><span class="dashicons dashicons-yes-alt"></span></td>
									</tr>
									<tr>
										<td><div class="nasio-tooltip"><span class="dashicons dashicons-editor-help"></span><span class="nasio-tooltiptext">
										<?php esc_html_e( 'Make the dark mode the default theme mode.', 'prespa' );
										?>
										</span></div>
										<?php esc_html_e( 'Make the dark mode default', 'prespa' );
										?>
										</td>
										<td><span class="dashicons dashicons-minus"></span></td>
										<td><span class="dashicons dashicons-yes-alt"></span></td>
									</tr>
									<tr>
										<td><div class="nasio-tooltip"><span class="dashicons dashicons-editor-help"></span><span class="nasio-tooltiptext">
										<?php esc_html_e( 'Display the number of post views for each post.', 'prespa' );
										?>
										</span></div>
										<?php esc_html_e( 'Show post views', 'prespa' );
										?>
										</td>
										<td><span class="dashicons dashicons-minus"></span></td>
										<td><span class="dashicons dashicons-yes-alt"></span></td>
									</tr>
									<tr>
										<td><div class="nasio-tooltip"><span class="dashicons dashicons-editor-help"></span><span class="nasio-tooltiptext">
										<?php esc_html_e( 'Show a beautiful heart icon on top of each post that displays the number of viewers who liked the post.', 'prespa' );
										?>
										</span></div>
										<?php esc_html_e( 'Show post likes', 'prespa' );
										?>
										</td>
										<td><span class="dashicons dashicons-minus"></span></td>
										<td><span class="dashicons dashicons-yes-alt"></span></td>
									</tr>
									<tr>
										<td><div class="nasio-tooltip"><span class="dashicons dashicons-editor-help"></span><span class="nasio-tooltiptext">
										<?php esc_html_e( 'Remove theme author credits and add your own copyright notice there. Customize the colors of the footer elements directly from the theme customizer. No coding skills needed!', 'prespa' );
										?>
										</span></div>
										<?php esc_html_e( 'Remove Footer Credits', 'prespa' );
										?>
										</td>
										<td><span class="dashicons dashicons-minus"></span></td>
										<td><span class="dashicons dashicons-yes-alt"></span></td>
									</tr>
									<tr>
										<td><div class="nasio-tooltip"><span class="dashicons dashicons-editor-help"></span><span class="nasio-tooltiptext">
											<?php esc_html_e( 'Make header menu items appear on top of the featured image. Choose per page.', 'prespa' ); ?>
											</span></div>
											<?php esc_html_e( 'Transparent Header', 'prespa' ); ?>
										</td>
										<td><span class="dashicons dashicons-minus"></span></td>
										<td><span class="dashicons dashicons-yes-alt"></span></td>
									</tr>
									<tr>
										<td><div class="nasio-tooltip"><span class="dashicons dashicons-editor-help"></span><span class="nasio-tooltiptext">
                                            <?php esc_html_e( 'Impress your visitors by adding an artistic flavor to your website. Convert your cursor into a beautiful transparent or filled circle, similar to sites featured in Awwwards.', 'prespa' );
                                            ?>
                                            </span></div>
                                            <?php esc_html_e( 'Custom cursor', 'prespa' );
                                            ?>
										</td>
										<td><span class="dashicons dashicons-minus"></span></td>
										<td><span class="dashicons dashicons-yes-alt"></span></td>
									</tr>
									<tr>
										<td>
										<?php esc_html_e( 'Support', 'prespa' );
										?>
										</td>
										<td><div class="nasio-tooltip">
											<span class="dashicons dashicons-editor-help"></span>
                                            <?php esc_html_e( 'Nonpriority', 'prespa' );
                                            ?>
                                            <span class="nasio-tooltiptext">
                                            <?php esc_html_e( 'Support is provided only in the WordPress.org community forums.', 'prespa' );
                                            ?>
                                            </span></div>
                                        </td>
										<td><div class="nasio-tooltip"><span class="dashicons dashicons-editor-help"></span><strong>
                                            <?php esc_html_e( 'Priority', 'prespa' );
                                            ?>
                                            </strong><span class="nasio-tooltiptext">
                                            <?php esc_html_e( 'Quick and friendly support is available via email', 'prespa' );
                                            ?>
                                            </span></div>
                                        </td>
									</tr>
									<tr>
										<td colspan="3" style="text-align: center;"><a class="button button-primary" href="
										<?php echo esc_url( PRESPA_THEME_UPGRADE_URL );
										?>
											" rel="noopener" target="_blank">
											<?php esc_html_e( 'Upgrade to Prespa PRO', 'prespa' );
											?>
											</a>
										</td>
									</tr>
								</table>

							</div><!-- .nasiothemes-doc-column-text-wrapper-->
						</div><!-- .doc-section -->
							<?php
						}

						?>
					</div><!-- .nasiothemes-doc-column-wrapper -->
				</li><!-- .nasiothemes-doc-column -->
			</ul><!-- .nasiothemes-doc-columns -->

		</div><!-- .nasiothemes-documentation -->

	</div><!-- .nasiothemes-wrapper -->

	<?php
}
