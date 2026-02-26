<?php
/**
 * Import script for Arden Courts of Anderson tour.
 *
 * Usage: Run via WP-CLI:
 *   wp eval-file /path/to/import-anderson.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	echo "Run this via WP-CLI: wp eval-file import-anderson.php\n";
	exit( 1 );
}

$s3_base = 'https://h3vt-tours-media.s3.us-east-1.amazonaws.com/h3vt-tours/arden-anderson';

// Reuse the existing Arden template (ID 322).
$template_id = 322;

/* ------------------------------------------------------------------
 * 1. Tour post
 * ----------------------------------------------------------------*/
$tour_id = wp_insert_post( array(
	'post_type'   => 'h3vt_tour',
	'post_title'  => 'Arden Courts of Anderson',
	'post_status' => 'publish',
) );

if ( is_wp_error( $tour_id ) ) {
	echo "Failed to create tour: " . $tour_id->get_error_message() . "\n";
	exit( 1 );
}

echo "Tour created: ID {$tour_id}\n";

// Link template.
update_field( 'tour_template', $template_id, $tour_id );

// Hero (image, not video).
update_field( 'hero_media_type', 'image', $tour_id );
update_field( 'hero_title', 'Arden Courts of Anderson', $tour_id );
update_field( 'hero_description', 'A Memory Care Community', $tour_id );

$hero_image = array(
	'id'        => 0,
	'url'       => "{$s3_base}/hero-image.jpg",
	'alt'       => 'Welcome to Arden Courts of Anderson',
	'title'     => 'hero-image',
	'filename'  => 'hero-image.jpg',
	'mime_type' => 'image/jpeg',
	'width'     => 0,
	'height'    => 0,
);
update_post_meta( $tour_id, 'hero_image', wp_json_encode( $hero_image ) );
update_post_meta( $tour_id, '_hero_image', 'field_h3vt_settings_hero_image' );

/* ------------------------------------------------------------------
 * 2. Navigation Categories
 * ----------------------------------------------------------------*/
$categories = array(
	array( 'nav_label' => 'Activity Rooms',      'nav_order' => 1 ),
	array( 'nav_label' => 'Common Areas',         'nav_order' => 2 ),
	array( 'nav_label' => 'Dining / Living Areas', 'nav_order' => 3 ),
	array( 'nav_label' => 'Outdoor Areas',        'nav_order' => 4 ),
	array( 'nav_label' => 'Resident Rooms',       'nav_order' => 5 ),
);
update_field( 'nav_categories', $categories, $tour_id );

/* ------------------------------------------------------------------
 * 3. Slides
 * ----------------------------------------------------------------*/
$slides = array(
	// ACTIVITY ROOMS
	array( 'title' => 'Art Studio',              'file' => '01-art-studio.jpg',       'cat' => 'Activity Rooms',        'desc' => '' ),
	array( 'title' => 'Art Studio',              'file' => '02-art-studio.jpg',       'cat' => 'Activity Rooms',        'desc' => '' ),
	array( 'title' => 'Art Studio',              'file' => '03-art-studio.jpg',       'cat' => 'Activity Rooms',        'desc' => '' ),
	array( 'title' => 'Large Community Center',  'file' => '04-community-center.jpg', 'cat' => 'Activity Rooms',        'desc' => 'Community areas provide additional space for events, mingling, exercise and fun.' ),
	array( 'title' => 'Large Community Center',  'file' => '05-community-center.jpg', 'cat' => 'Activity Rooms',        'desc' => 'Community areas provide additional space for events, mingling, exercise and fun.' ),
	array( 'title' => 'Activities',              'file' => '06-activities.jpg',       'cat' => 'Activity Rooms',        'desc' => '' ),
	array( 'title' => 'Resident Art Work',       'file' => '07-resident-art-work.jpg','cat' => 'Activity Rooms',        'desc' => '' ),
	// COMMON AREAS
	array( 'title' => 'Welcome',                 'file' => '08-welcome.png',          'cat' => 'Common Areas',          'desc' => '', 'mime' => 'image/png' ),
	array( 'title' => 'Core Area',               'file' => '09-core-area.jpg',        'cat' => 'Common Areas',          'desc' => 'The core area of Arden Courts helps our residents navigate our community by using pictures on our signage for the rooms and includes our themed porch areas.' ),
	array( 'title' => 'Core Area',               'file' => '10-core-area.jpg',        'cat' => 'Common Areas',          'desc' => '' ),
	array( 'title' => 'Themed Porch Areas',      'file' => '11-themed-porch.jpg',     'cat' => 'Common Areas',          'desc' => 'A place to people watch, rest, and chat with others.' ),
	array( 'title' => 'Core Area',               'file' => '12-core-area.jpg',        'cat' => 'Common Areas',          'desc' => '' ),
	array( 'title' => 'Themed Porch Areas',      'file' => '13-themed-porch.jpg',     'cat' => 'Common Areas',          'desc' => 'A place to people watch, rest, and chat with others.' ),
	array( 'title' => 'Core Area',               'file' => '14-core-area.jpg',        'cat' => 'Common Areas',          'desc' => '' ),
	array( 'title' => 'Themed Porch Areas',      'file' => '15-themed-porch.jpg',     'cat' => 'Common Areas',          'desc' => 'A place to people watch, rest, and chat with others.' ),
	array( 'title' => 'Core Area',               'file' => '16-core-area.jpg',        'cat' => 'Common Areas',          'desc' => '' ),
	array( 'title' => 'Themed Porch Areas',      'file' => '17-themed-porch.jpg',     'cat' => 'Common Areas',          'desc' => 'A place to people watch, rest, and chat with others.' ),
	array( 'title' => 'Beauty Salon',            'file' => '18-beauty-salon.jpg',     'cat' => 'Common Areas',          'desc' => '' ),
	// DINING / LIVING AREAS
	array( 'title' => 'Living Room',             'file' => '19-living-room.jpg',      'cat' => 'Dining / Living Areas', 'desc' => 'Furnishings which are comfortable and familiar.' ),
	array( 'title' => 'Living Room',             'file' => '20-living-room.jpg',      'cat' => 'Dining / Living Areas', 'desc' => 'Furnishings which are comfortable and familiar.' ),
	array( 'title' => 'Living Room',             'file' => '21-living-room.jpg',      'cat' => 'Dining / Living Areas', 'desc' => 'Furnishings which are comfortable and familiar.' ),
	array( 'title' => 'Neighborhood Kitchen',    'file' => '22-kitchen.jpg',          'cat' => 'Dining / Living Areas', 'desc' => 'Food is served and delivered in a family-style environment.' ),
	array( 'title' => 'Neighborhood Kitchen',    'file' => '23-kitchen.jpg',          'cat' => 'Dining / Living Areas', 'desc' => 'Food is served and delivered in a family-style environment.' ),
	array( 'title' => 'Neighborhood Dining Room','file' => '24-dining-room.jpg',      'cat' => 'Dining / Living Areas', 'desc' => 'An inviting place for residents to share meals and conversation.' ),
	array( 'title' => 'Neighborhood Dining Room','file' => '25-dining-room.jpg',      'cat' => 'Dining / Living Areas', 'desc' => 'An inviting place for residents to share meals and conversation.' ),
	// OUTDOOR AREAS
	array( 'title' => 'Four Houses Overview',    'file' => '26-four-houses.jpg',      'cat' => 'Outdoor Areas',         'desc' => 'Arden Courts is organized into four separate houses, each with its own living room, dining room, kitchen, full bath and laundry.' ),
	array( 'title' => 'Outdoor Areas',           'file' => '27-outdoor.jpg',          'cat' => 'Outdoor Areas',         'desc' => '' ),
	array( 'title' => 'Outdoor Areas',           'file' => '28-outdoor.jpg',          'cat' => 'Outdoor Areas',         'desc' => '' ),
	array( 'title' => 'Outdoor Areas',           'file' => '29-outdoor.jpg',          'cat' => 'Outdoor Areas',         'desc' => '' ),
	array( 'title' => 'Outdoor Areas',           'file' => '30-outdoor.jpg',          'cat' => 'Outdoor Areas',         'desc' => '' ),
	array( 'title' => 'Welcome Exterior',        'file' => '31-welcome-exterior.jpg', 'cat' => 'Outdoor Areas',         'desc' => '' ),
	// RESIDENT ROOMS
	array( 'title' => 'Resident Room',           'file' => '32-resident-room.jpg',    'cat' => 'Resident Rooms',        'desc' => 'Beautifully designed rooms are cozy, bright and functional for the unique needs of persons living with dementia.' ),
	array( 'title' => 'Resident Room',           'file' => '33-resident-room.jpg',    'cat' => 'Resident Rooms',        'desc' => 'Beautifully designed rooms are cozy, bright and functional for the unique needs of persons living with dementia.' ),
	array( 'title' => 'Resident Room',           'file' => '34-resident-room.jpg',    'cat' => 'Resident Rooms',        'desc' => 'Beautifully designed rooms are cozy, bright and functional for the unique needs of persons living with dementia.' ),
	array( 'title' => 'Resident Room',           'file' => '35-resident-room.jpg',    'cat' => 'Resident Rooms',        'desc' => 'Beautifully designed rooms are cozy, bright and functional for the unique needs of persons living with dementia.' ),
);

$slide_rows = array();
foreach ( $slides as $s ) {
	$mime = isset( $s['mime'] ) ? $s['mime'] : 'image/jpeg';
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
			'mime_type' => $mime,
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
 * 4. Contact
 * ----------------------------------------------------------------*/
update_field( 'enable_contact', 1, $tour_id );
update_field( 'contact_facility_name', 'Arden Courts of Anderson', $tour_id );
update_field( 'contact_address', "6870 Clough Pike\nCincinnati, OH 45244", $tour_id );
update_field( 'contact_email', 'seniorlivingconnect@arden-courts.com', $tour_id );
update_field( 'contact_phone', '513-233-0831', $tour_id );
update_field( 'google_maps_embed_url', 'https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3096.4752357714397!2d-84.36911038464375!3d39.0956492795405!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x8841aea5cefb61d5%3A0xb6cdb6dd04ea1c1c!2s6870%20Clough%20Pike%2C%20Cincinnati%2C%20OH%2045244!5e0!3m2!1sen!2sus!4v1653833258187!5m2!1sen!2sus', $tour_id );

/* ------------------------------------------------------------------
 * 5. Floor plans
 * ----------------------------------------------------------------*/
update_field( 'enable_floorplans', 1, $tour_id );

$floorplans = array(
	array(
		'floorplan_label' => 'Community Floor Plan',
		'floorplan_image' => array(
			'id'        => 0,
			'url'       => "{$s3_base}/floorplan-community.png",
			'alt'       => 'Community Floor Plan',
			'title'     => 'floorplan-community',
			'filename'  => 'floorplan-community.png',
			'mime_type' => 'image/png',
			'width'     => 0,
			'height'    => 0,
		),
	),
	array(
		'floorplan_label' => 'House Floor Plan',
		'floorplan_image' => array(
			'id'        => 0,
			'url'       => "{$s3_base}/floorplan-house.png",
			'alt'       => 'House Floor Plan',
			'title'     => 'floorplan-house',
			'filename'  => 'floorplan-house.png',
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
 * 6. Embedded 3D Tour (Matterport)
 * ----------------------------------------------------------------*/
$embedded_tours = array(
	array(
		'tour_label'     => '3D Tour',
		'tour_embed_url' => 'https://my.matterport.com/show/?m=7aSzd6Kvegc',
	),
);
update_field( 'embedded_tours', $embedded_tours, $tour_id );

/* ------------------------------------------------------------------
 * 7. Testimonials
 * ----------------------------------------------------------------*/
update_field( 'enable_testimonials', 1, $tour_id );

$testimonials = array(
	array(
		'person_name' => '',
		'person_role' => 'Resident Services Supervisor',
		'video_url'   => "{$s3_base}/testimonial-resident-services.mp4",
		'thumbnail'   => array(
			'id'        => 0,
			'url'       => "{$s3_base}/testimonial-resident-services-poster.jpg",
			'alt'       => 'Resident Services Supervisor',
			'title'     => 'testimonial-resident-services-poster',
			'filename'  => 'testimonial-resident-services-poster.jpg',
			'mime_type' => 'image/jpeg',
			'width'     => 0,
			'height'    => 0,
		),
	),
	array(
		'person_name' => '',
		'person_role' => 'Spouse',
		'video_url'   => "{$s3_base}/testimonial-spouse.mp4",
		'thumbnail'   => array(
			'id'        => 0,
			'url'       => "{$s3_base}/testimonial-spouse-poster.jpg",
			'alt'       => 'Spouse',
			'title'     => 'testimonial-spouse-poster',
			'filename'  => 'testimonial-spouse-poster.jpg',
			'mime_type' => 'image/jpeg',
			'width'     => 0,
			'height'    => 0,
		),
	),
);

update_post_meta( $tour_id, 'testimonials', count( $testimonials ) );
update_post_meta( $tour_id, '_testimonials', 'field_h3vt_testimonials_items' );

foreach ( $testimonials as $i => $t ) {
	$prefix = "testimonials_{$i}_";

	update_post_meta( $tour_id, "{$prefix}person_name", $t['person_name'] );
	update_post_meta( $tour_id, "_{$prefix}person_name", 'field_h3vt_testimonials_person_name' );

	update_post_meta( $tour_id, "{$prefix}person_role", $t['person_role'] );
	update_post_meta( $tour_id, "_{$prefix}person_role", 'field_h3vt_testimonials_person_role' );

	update_post_meta( $tour_id, "{$prefix}video_url", $t['video_url'] );
	update_post_meta( $tour_id, "_{$prefix}video_url", 'field_h3vt_testimonials_video_url' );

	update_post_meta( $tour_id, "{$prefix}thumbnail", wp_json_encode( $t['thumbnail'] ) );
	update_post_meta( $tour_id, "_{$prefix}thumbnail", 'field_h3vt_testimonials_thumbnail' );
}

echo "\nImport complete!\n";
echo "Template: {$template_id} (existing Arden)\n";
echo "Tour:     {$tour_id}\n";
echo "Slides:   " . count( $slide_rows ) . "\n";
echo "URL:      /tour/arden-courts-of-anderson/\n";
