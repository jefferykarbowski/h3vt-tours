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

// Remove legacy S3 credentials option.
delete_option( 'h3vt_tours_s3_settings' );

// Remove the tour media flag from all attachments (the attachments themselves remain).
$h3vt_flagged = get_posts(
	array(
		'numberposts' => -1,
		'post_type'   => 'attachment',
		'post_status' => 'any',
		'meta_key'    => '_h3vt_tour_media',
		'fields'      => 'ids',
	)
);

foreach ( $h3vt_flagged as $h3vt_att_id ) {
	delete_post_meta( $h3vt_att_id, '_h3vt_tour_media' );
}

flush_rewrite_rules();
