<?php
/**
 * Import script for The Auberge at Naperville tour.
 *
 * Usage: Run via WP-CLI:
 *   wp eval-file /path/to/import-naperville.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	echo "Run this via WP-CLI: wp eval-file import-naperville.php\n";
	exit( 1 );
}

$s3_base = 'https://h3vt-tours-media.s3.us-east-1.amazonaws.com/h3vt-tours/auberge-naperville';

// Reuse the Cedar Park logo (same Auberge brand).
$logo_url = 'https://h3vt-tours-media.s3.us-east-1.amazonaws.com/h3vt-tours/auberge-cedar-park/logo.png';

/* ------------------------------------------------------------------
 * 1. Template
 * ----------------------------------------------------------------*/
$template_id = wp_insert_post( array(
	'post_type'   => 'h3vt_tour_template',
	'post_title'  => 'Auberge Naperville',
	'post_status' => 'publish',
) );

if ( is_wp_error( $template_id ) ) {
	echo "Failed to create template: " . $template_id->get_error_message() . "\n";
	exit( 1 );
}

echo "Template created: ID {$template_id}\n";

// Template fields.
update_field( 'primary_color', '#FF6B00', $template_id );
update_field( 'secondary_color', '#1A1A1A', $template_id );
update_field( 'text_color', '#FFFFFF', $template_id );
update_field( 'button_style', 'text', $template_id );
update_field( 'autoplay_speed', 8, $template_id );

// Logo (reuse Auberge brand logo).
$logo_value = array(
	'id'        => 0,
	'url'       => $logo_url,
	'alt'       => 'The Auberge',
	'title'     => 'auberge-logo',
	'filename'  => 'logo.png',
	'mime_type' => 'image/png',
	'width'     => 300,
	'height'    => 100,
);
update_post_meta( $template_id, 'logo', wp_json_encode( $logo_value ) );
update_post_meta( $template_id, '_logo', 'field_h3vt_tpl_logo' );

/* ------------------------------------------------------------------
 * 2. Tour post
 * ----------------------------------------------------------------*/
$tour_id = wp_insert_post( array(
	'post_type'   => 'h3vt_tour',
	'post_title'  => 'The Auberge at Naperville',
	'post_status' => 'publish',
) );

if ( is_wp_error( $tour_id ) ) {
	echo "Failed to create tour: " . $tour_id->get_error_message() . "\n";
	exit( 1 );
}

echo "Tour created: ID {$tour_id}\n";

// Link template.
update_field( 'tour_template', $template_id, $tour_id );

// Hero.
update_field( 'hero_media_type', 'video', $tour_id );
update_field( 'hero_title', 'The Auberge at Naperville', $tour_id );
update_field( 'hero_description', 'A Memory Care Community', $tour_id );

$hero_video = array(
	'id'        => 0,
	'url'       => "{$s3_base}/hero-video.mp4",
	'alt'       => '',
	'title'     => 'hero-video',
	'filename'  => 'hero-video.mp4',
	'mime_type' => 'video/mp4',
	'width'     => 1920,
	'height'    => 1080,
);
update_post_meta( $tour_id, 'hero_video', wp_json_encode( $hero_video ) );
update_post_meta( $tour_id, '_hero_video', 'field_h3vt_settings_hero_video' );

/* ------------------------------------------------------------------
 * 3. Navigation Categories
 * ----------------------------------------------------------------*/
$categories = array(
	array( 'nav_label' => 'Amenities',      'nav_order' => 1 ),
	array( 'nav_label' => 'Dining',          'nav_order' => 2 ),
	array( 'nav_label' => 'Outdoor Areas',   'nav_order' => 3 ),
	array( 'nav_label' => 'Resident Rooms',  'nav_order' => 4 ),
	array( 'nav_label' => 'Aerial Views',    'nav_order' => 5 ),
);
update_field( 'nav_categories', $categories, $tour_id );

/* ------------------------------------------------------------------
 * 4. Slides
 * ----------------------------------------------------------------*/
$slides = array(
	// AMENITIES
	array( 'title' => 'Lobby',                  'file' => '01-lobby.jpg',                  'cat' => 'Amenities',     'desc' => 'Welcome to The Auberge at Naperville' ),
	array( 'title' => 'Lobby',                  'file' => '02-lobby.jpg',                  'cat' => 'Amenities',     'desc' => '' ),
	array( 'title' => 'Lobby Sitting Area',     'file' => '03-lobby-sitting-area.jpg',     'cat' => 'Amenities',     'desc' => '' ),
	array( 'title' => 'Bistro',                 'file' => '04-bistro.jpg',                 'cat' => 'Amenities',     'desc' => '' ),
	array( 'title' => 'Bistro',                 'file' => '05-bistro.jpg',                 'cat' => 'Amenities',     'desc' => '' ),
	array( 'title' => 'General Store',          'file' => '06-general-store.jpg',          'cat' => 'Amenities',     'desc' => '' ),
	array( 'title' => 'Neighborhood Nook',      'file' => '07-neighborhood-nook.jpg',      'cat' => 'Amenities',     'desc' => '' ),
	array( 'title' => 'Beauty Salon',           'file' => '08-beauty-salon.jpg',           'cat' => 'Amenities',     'desc' => '' ),
	array( 'title' => 'Spa',                    'file' => '09-spa.jpg',                    'cat' => 'Amenities',     'desc' => '' ),
	array( 'title' => 'Spa Tub',                'file' => '10-spa-tub.jpg',                'cat' => 'Amenities',     'desc' => '' ),
	array( 'title' => 'Activity Room',          'file' => '11-activity-room.jpg',          'cat' => 'Amenities',     'desc' => '' ),
	array( 'title' => 'Game Table',             'file' => '12-game-table.jpg',             'cat' => 'Amenities',     'desc' => '' ),
	// DINING
	array( 'title' => 'Dining Room',            'file' => '13-dining-room.jpg',            'cat' => 'Dining',        'desc' => '' ),
	array( 'title' => 'Dining Room',            'file' => '14-dining-room.jpg',            'cat' => 'Dining',        'desc' => '' ),
	array( 'title' => 'Dining Room',            'file' => '15-dining-room.jpg',            'cat' => 'Dining',        'desc' => '' ),
	array( 'title' => 'Dining Room',            'file' => '16-dining-room.jpg',            'cat' => 'Dining',        'desc' => '' ),
	array( 'title' => 'Private Dining Room',    'file' => '17-private-dining-room.jpg',    'cat' => 'Dining',        'desc' => '' ),
	array( 'title' => 'Private Dining Room',    'file' => '18-private-dining-room.jpg',    'cat' => 'Dining',        'desc' => '' ),
	array( 'title' => 'Private Dining Room',    'file' => '19-private-dining-room.jpg',    'cat' => 'Dining',        'desc' => '' ),
	// OUTDOOR AREAS
	array( 'title' => 'Courtyard',              'file' => '20-courtyard.jpg',              'cat' => 'Outdoor Areas', 'desc' => 'Spacious and Secure Enclosed Courtyard' ),
	array( 'title' => 'Gazebo',                 'file' => '21-gazebo.jpg',                 'cat' => 'Outdoor Areas', 'desc' => '' ),
	array( 'title' => 'Gazebo',                 'file' => '22-gazebo.jpg',                 'cat' => 'Outdoor Areas', 'desc' => '' ),
	array( 'title' => 'Gazebo & Courtyard',     'file' => '23-gazebo-and-courtyard.jpg',   'cat' => 'Outdoor Areas', 'desc' => '' ),
	array( 'title' => 'Front Entrance',         'file' => '24-front-entrance.jpg',         'cat' => 'Outdoor Areas', 'desc' => '' ),
	array( 'title' => 'Front Entrance',         'file' => '25-front-entrance.jpg',         'cat' => 'Outdoor Areas', 'desc' => '' ),
	array( 'title' => 'Sassy the Facility Dog', 'file' => '26-sassy-the-facility-dog.jpg', 'cat' => 'Outdoor Areas', 'desc' => '' ),
	// RESIDENT ROOMS
	array( 'title' => 'Resident Room',          'file' => '27-resident-room.jpg',          'cat' => 'Resident Rooms', 'desc' => '' ),
	array( 'title' => 'Resident Room',          'file' => '28-resident-room.jpg',          'cat' => 'Resident Rooms', 'desc' => '' ),
	array( 'title' => 'Resident Room',          'file' => '29-resident-room.jpg',          'cat' => 'Resident Rooms', 'desc' => '' ),
	array( 'title' => 'Resident Room',          'file' => '30-resident-room.jpg',          'cat' => 'Resident Rooms', 'desc' => '' ),
	// AERIAL VIEWS
	array( 'title' => 'Aerial View',            'file' => '31-aerial-view.jpg',            'cat' => 'Aerial Views',  'desc' => '' ),
	array( 'title' => 'Aerial View',            'file' => '32-aerial-view.jpg',            'cat' => 'Aerial Views',  'desc' => '' ),
	array( 'title' => 'Aerial View of the Courtyard', 'file' => '33-aerial-view-courtyard.jpg', 'cat' => 'Aerial Views', 'desc' => '' ),
	array( 'title' => 'Aerial View of the Courtyard', 'file' => '34-aerial-view-courtyard.jpg', 'cat' => 'Aerial Views', 'desc' => '' ),
	array( 'title' => 'Aerial View of the Courtyard', 'file' => '35-aerial-view-courtyard.jpg', 'cat' => 'Aerial Views', 'desc' => '' ),
);

$slide_rows = array();
foreach ( $slides as $s ) {
	$slide_rows[] = array(
		'slide_title'        => $s['title'],
		'slide_description'  => $s['desc'],
		'slide_nav_category' => $s['cat'],
		'slide_image'        => array(
			'id'        => 0,
			'url'       => "{$s3_base}/slides/{$s['file']}",
			'alt'       => $s['title'],
			'title'     => pathinfo( $s['file'], PATHINFO_FILENAME ),
			'filename'  => $s['file'],
			'mime_type' => 'image/jpeg',
			'width'     => 0,
			'height'    => 0,
		),
	);
}

// Write repeater count.
update_post_meta( $tour_id, 'slides', count( $slide_rows ) );
update_post_meta( $tour_id, '_slides', 'field_h3vt_navigation_slides' );

foreach ( $slide_rows as $i => $row ) {
	$prefix = "slides_{$i}_";

	update_post_meta( $tour_id, "{$prefix}slide_title", $row['slide_title'] );
	update_post_meta( $tour_id, "_{$prefix}slide_title", 'field_h3vt_navigation_slide_title' );

	update_post_meta( $tour_id, "{$prefix}slide_description", $row['slide_description'] );
	update_post_meta( $tour_id, "_{$prefix}slide_description", 'field_h3vt_navigation_slide_description' );

	update_post_meta( $tour_id, "{$prefix}slide_nav_category", $row['slide_nav_category'] );
	update_post_meta( $tour_id, "_{$prefix}slide_nav_category", 'field_h3vt_navigation_slide_nav_category' );

	update_post_meta( $tour_id, "{$prefix}slide_image", wp_json_encode( $row['slide_image'] ) );
	update_post_meta( $tour_id, "_{$prefix}slide_image", 'field_h3vt_navigation_slide_image' );
}

/* ------------------------------------------------------------------
 * 5. Contact
 * ----------------------------------------------------------------*/
update_field( 'enable_contact', 1, $tour_id );
update_field( 'contact_facility_name', 'The Auberge at Naperville', $tour_id );
update_field( 'contact_address', "1936 Brookdale Rd\nNaperville, IL 60563", $tour_id );
update_field( 'contact_email', 'SrLivingConnect@egmgmt.com', $tour_id );
update_field( 'contact_phone', '', $tour_id );
update_field( 'google_maps_embed_url', 'https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d2976.6!2d-88.1725!3d41.7558!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x880e57a0a0a0a0a0%3A0x0!2s1936+Brookdale+Rd%2C+Naperville%2C+IL+60563!5e0!3m2!1sen!2sus!4v1700000000000', $tour_id );

/* ------------------------------------------------------------------
 * 6. Floor plans
 * ----------------------------------------------------------------*/
update_field( 'enable_floorplans', 1, $tour_id );

$floorplans = array(
	array(
		'floorplan_label' => 'Semi-Private Room',
		'floorplan_image' => array(
			'id'        => 0,
			'url'       => "{$s3_base}/floorplan-semi-private.png",
			'alt'       => 'Semi-Private Room Floor Plan',
			'title'     => 'floorplan-semi-private',
			'filename'  => 'floorplan-semi-private.png',
			'mime_type' => 'image/png',
			'width'     => 0,
			'height'    => 0,
		),
	),
	array(
		'floorplan_label' => 'Private Room',
		'floorplan_image' => array(
			'id'        => 0,
			'url'       => "{$s3_base}/floorplan-private.png",
			'alt'       => 'Private Room Floor Plan',
			'title'     => 'floorplan-private',
			'filename'  => 'floorplan-private.png',
			'mime_type' => 'image/png',
			'width'     => 0,
			'height'    => 0,
		),
	),
);

update_post_meta( $tour_id, 'floorplans', count( $floorplans ) );
update_post_meta( $tour_id, '_floorplans', 'field_h3vt_floorplans_items' );

foreach ( $floorplans as $i => $fp ) {
	$prefix = "floorplans_{$i}_";

	update_post_meta( $tour_id, "{$prefix}floorplan_label", $fp['floorplan_label'] );
	update_post_meta( $tour_id, "_{$prefix}floorplan_label", 'field_h3vt_floorplans_label' );

	update_post_meta( $tour_id, "{$prefix}floorplan_image", wp_json_encode( $fp['floorplan_image'] ) );
	update_post_meta( $tour_id, "_{$prefix}floorplan_image", 'field_h3vt_floorplans_image' );
}

/* ------------------------------------------------------------------
 * 7. Embedded 3D Tour (Matterport)
 * ----------------------------------------------------------------*/
$embedded_tours = array(
	array(
		'tour_label'     => '3D Tour',
		'tour_embed_url' => 'https://my.matterport.com/show/?m=ZEGgVyiiP1s',
	),
);
update_field( 'embedded_tours', $embedded_tours, $tour_id );

/* ------------------------------------------------------------------
 * 8. Disabled features
 * ----------------------------------------------------------------*/
update_field( 'enable_testimonials', 0, $tour_id );

echo "\nImport complete!\n";
echo "Template: {$template_id}\n";
echo "Tour:     {$tour_id}\n";
echo "Slides:   " . count( $slide_rows ) . "\n";
echo "URL:      /tour/the-auberge-at-naperville/\n";
