<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * Removes all h3vt_tour posts and flushes rewrite rules.
 *
 * @package H3VT_Tours
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	die;
}

$h3vt_tours_posts = get_posts(
	array(
		'numberposts' => -1,
		'post_type'   => 'h3vt_tour',
		'post_status' => 'any',
	)
);

foreach ( $h3vt_tours_posts as $h3vt_post ) {
	wp_delete_post( $h3vt_post->ID, true );
}

flush_rewrite_rules();
