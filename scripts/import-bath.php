<?php
/**
 * Import script for Arden Courts of Bath tour.
 *
 * Usage: Run via WP-CLI:
 *   wp eval-file /path/to/import-bath.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	echo "Run this via WP-CLI: wp eval-file import-bath.php\n";
	exit( 1 );
}

$s3_base = 'https://h3vt-tours-media.s3.us-east-1.amazonaws.com/h3vt-tours/arden-bath';

// Reuse the existing Arden template (ID 322).
$template_id = 322;

/* ------------------------------------------------------------------
 * 1. Tour post
 * ----------------------------------------------------------------*/
$tour_id = wp_insert_post( array(
	'post_type'   => 'h3vt_tour',
	'post_title'  => 'Arden Courts of Bath',
	'post_status' => 'publish',
) );

if ( is_wp_error( $tour_id ) ) {
	echo "Failed to create tour: " . $tour_id->get_error_message() . "\n";
	exit( 1 );
}

echo "Tour created: ID {$tour_id}\n";

// Link template.
update_field( 'tour_template', $template_id, $tour_id );

// Hero (video — drone aerial).
update_field( 'hero_media_type', 'video', $tour_id );
update_field( 'hero_title', 'Arden Courts of Bath', $tour_id );
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
 * 2. Navigation Categories
 * ----------------------------------------------------------------*/
$categories = array(
	array( 'nav_label' => 'Common Areas',          'nav_order' => 1 ),
	array( 'nav_label' => 'Dining / Living Areas',  'nav_order' => 2 ),
	array( 'nav_label' => 'Activity Rooms',         'nav_order' => 3 ),
	array( 'nav_label' => 'Outdoor Areas',          'nav_order' => 4 ),
	array( 'nav_label' => 'Resident Rooms',         'nav_order' => 5 ),
);
update_field( 'nav_categories', $categories, $tour_id );

/* ------------------------------------------------------------------
 * 3. Slides
 * ----------------------------------------------------------------*/
$slides = array(
	// COMMON AREAS
	array( 'title' => 'Core',                    'file' => '01-core.jpg',               'cat' => 'Common Areas',          'desc' => 'The core area of Arden Courts helps our residents navigate our community by using pictures on our signage for the rooms and includes our themed porch areas.' ),
	array( 'title' => 'Themed Porch Areas',      'file' => '02-themed-porch.jpg',       'cat' => 'Common Areas',          'desc' => 'A place to people watch, rest, and chat with others.' ),
	array( 'title' => 'Core Area',               'file' => '03-core-area.jpg',          'cat' => 'Common Areas',          'desc' => '' ),
	array( 'title' => 'Core Area',               'file' => '04-core-area.jpg',          'cat' => 'Common Areas',          'desc' => '' ),
	array( 'title' => 'Core Area',               'file' => '05-core-area.jpg',          'cat' => 'Common Areas',          'desc' => '' ),
	array( 'title' => 'Themed Porch Areas',      'file' => '06-themed-porch.jpg',       'cat' => 'Common Areas',          'desc' => 'A place to people watch, rest, and chat with others.' ),
	array( 'title' => 'Core',                    'file' => '07-core.jpg',               'cat' => 'Common Areas',          'desc' => '' ),
	array( 'title' => 'Themed Porch Areas',      'file' => '08-themed-porch.jpg',       'cat' => 'Common Areas',          'desc' => 'A place to people watch, rest, and chat with others.' ),
	// DINING / LIVING AREAS
	array( 'title' => 'Neighborhood Kitchen',    'file' => '09-kitchen.jpg',            'cat' => 'Dining / Living Areas', 'desc' => 'Food is served and delivered in a family-style environment.' ),
	array( 'title' => 'Neighborhood Kitchen',    'file' => '10-kitchen.jpg',            'cat' => 'Dining / Living Areas', 'desc' => 'Food is served and delivered in a family-style environment.' ),
	array( 'title' => 'Neighborhood Kitchen',    'file' => '11-kitchen.jpg',            'cat' => 'Dining / Living Areas', 'desc' => 'Food is served and delivered in a family-style environment.' ),
	array( 'title' => 'Neighborhood Dining Room','file' => '12-dining-room.jpg',        'cat' => 'Dining / Living Areas', 'desc' => 'An inviting place for residents to share meals and conversation.' ),
	array( 'title' => 'Neighborhood Dining Room','file' => '13-dining-room.jpg',        'cat' => 'Dining / Living Areas', 'desc' => 'An inviting place for residents to share meals and conversation.' ),
	array( 'title' => 'Neighborhood Dining Room','file' => '14-dining-room.jpg',        'cat' => 'Dining / Living Areas', 'desc' => 'An inviting place for residents to share meals and conversation.' ),
	array( 'title' => 'Neighborhood Dining Room','file' => '15-dining-room.jpg',        'cat' => 'Dining / Living Areas', 'desc' => 'An inviting place for residents to share meals and conversation.' ),
	array( 'title' => 'Living Room',             'file' => '16-living-room.jpg',        'cat' => 'Dining / Living Areas', 'desc' => 'Beautifully designed rooms are cozy, bright and functional.' ),
	array( 'title' => 'Living Room',             'file' => '17-living-room.jpg',        'cat' => 'Dining / Living Areas', 'desc' => 'Beautifully designed rooms are cozy, bright and functional.' ),
	// ACTIVITY ROOMS
	array( 'title' => 'Large Community Center',  'file' => '18-community-center.jpg',   'cat' => 'Activity Rooms',        'desc' => 'Community areas provide additional space for events, mingling, exercise and fun.' ),
	array( 'title' => 'Large Community Center',  'file' => '19-community-center.jpg',   'cat' => 'Activity Rooms',        'desc' => 'Community areas provide additional space for events, mingling, exercise and fun.' ),
	array( 'title' => 'Large Community Center',  'file' => '20-community-center.jpg',   'cat' => 'Activity Rooms',        'desc' => 'Community areas provide additional space for events, mingling, exercise and fun.' ),
	array( 'title' => 'The Studio',              'file' => '21-the-studio.jpg',         'cat' => 'Activity Rooms',        'desc' => 'You will find our residents engaging in discussion groups and creating wonderful pieces of art in our art studio.' ),
	// OUTDOOR AREAS
	array( 'title' => 'Welcome Exterior',        'file' => '22-welcome-exterior.jpg',   'cat' => 'Outdoor Areas',         'desc' => '' ),
	array( 'title' => 'Welcome Exterior',        'file' => '23-welcome-exterior.jpg',   'cat' => 'Outdoor Areas',         'desc' => '' ),
	array( 'title' => 'Four Houses Overview',    'file' => '24-four-houses.jpg',        'cat' => 'Outdoor Areas',         'desc' => 'Arden Courts is organized into four separate houses, each with its own living room, dining room, kitchen, full bath and laundry.' ),
	array( 'title' => 'Outdoor Areas',           'file' => '25-outdoor.jpg',            'cat' => 'Outdoor Areas',         'desc' => 'Our enclosed, spacious courtyards offer safe walking paths, gardening opportunities and seating areas.' ),
	array( 'title' => 'Outdoor Areas',           'file' => '26-outdoor.jpg',            'cat' => 'Outdoor Areas',         'desc' => 'Our enclosed, spacious courtyards offer safe walking paths, gardening opportunities and seating areas.' ),
	array( 'title' => 'Outdoor Areas',           'file' => '27-outdoor.jpg',            'cat' => 'Outdoor Areas',         'desc' => 'Our enclosed, spacious courtyards offer safe walking paths, gardening opportunities and seating areas.' ),
	array( 'title' => 'Outdoor Areas',           'file' => '28-outdoor.jpg',            'cat' => 'Outdoor Areas',         'desc' => 'Our enclosed, spacious courtyards offer safe walking paths, gardening opportunities and seating areas.' ),
	array( 'title' => 'Outdoor Areas',           'file' => '29-outdoor.jpg',            'cat' => 'Outdoor Areas',         'desc' => 'Our enclosed, spacious courtyards offer safe walking paths, gardening opportunities and seating areas.' ),
	array( 'title' => 'Outdoor Areas',           'file' => '30-outdoor.jpg',            'cat' => 'Outdoor Areas',         'desc' => 'Our enclosed, spacious courtyards offer safe walking paths, gardening opportunities and seating areas.' ),
	array( 'title' => 'Outdoor Areas',           'file' => '31-outdoor.jpg',            'cat' => 'Outdoor Areas',         'desc' => 'Our enclosed, spacious courtyards offer safe walking paths, gardening opportunities and seating areas.' ),
	array( 'title' => 'Outdoor Areas',           'file' => '32-outdoor.jpg',            'cat' => 'Outdoor Areas',         'desc' => 'Our enclosed, spacious courtyards offer safe walking paths, gardening opportunities and seating areas.' ),
	array( 'title' => 'Aerial View',             'file' => '33-aerial-view.jpg',        'cat' => 'Outdoor Areas',         'desc' => '' ),
	// RESIDENT ROOMS
	array( 'title' => 'Resident Room',           'file' => '34-resident-room.jpg',      'cat' => 'Resident Rooms',        'desc' => 'Beautifully designed rooms are cozy, bright and functional for the unique needs of persons living with dementia.' ),
	array( 'title' => 'Resident Room',           'file' => '35-resident-room.jpg',      'cat' => 'Resident Rooms',        'desc' => 'Beautifully designed rooms are cozy, bright and functional for the unique needs of persons living with dementia.' ),
	array( 'title' => 'Resident Room',           'file' => '36-resident-room.jpg',      'cat' => 'Resident Rooms',        'desc' => 'Beautifully designed rooms are cozy, bright and functional for the unique needs of persons living with dementia.' ),
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
 * 4. Contact
 * ----------------------------------------------------------------*/
update_field( 'enable_contact', 1, $tour_id );
update_field( 'contact_facility_name', 'Arden Courts of Bath', $tour_id );
update_field( 'contact_address', "171 N. Cleveland Massillon Road\nAkron, OH 44333", $tour_id );
update_field( 'contact_email', 'seniorlivingconnect@arden-courts.com', $tour_id );
update_field( 'contact_phone', '330-668-6889', $tour_id );
update_field( 'google_maps_embed_url', 'https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3004.7238922007314!2d-81.6388762239528!3d41.140553071332334!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x8830da777d4fa6e3%3A0x1e1f28c43d4bc82!2s171%20N%20Cleveland%20Massillon%20Rd%2C%20Akron%2C%20OH%2044333!5e0!3m2!1sen!2sus!4v1708697129283!5m2!1sen!2sus', $tour_id );

/* ------------------------------------------------------------------
 * 5. Floor plans
 * ----------------------------------------------------------------*/
update_field( 'enable_floorplans', 1, $tour_id );

$floorplans = array(
	array(
		'floorplan_label' => 'Residential Community Floor Plan',
		'floorplan_image' => array(
			'id'        => 0,
			'url'       => "{$s3_base}/floorplan-residential.jpg",
			'alt'       => 'Residential Community Floor Plan',
			'title'     => 'floorplan-residential',
			'filename'  => 'floorplan-residential.jpg',
			'mime_type' => 'image/jpeg',
			'width'     => 0,
			'height'    => 0,
		),
	),
	array(
		'floorplan_label' => 'Overall Floor Plan',
		'floorplan_image' => array(
			'id'        => 0,
			'url'       => "{$s3_base}/floorplan-overall.jpg",
			'alt'       => 'Overall Floor Plan',
			'title'     => 'floorplan-overall',
			'filename'  => 'floorplan-overall.jpg',
			'mime_type' => 'image/jpeg',
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
		'tour_embed_url' => 'https://my.matterport.com/show/?m=QXFr4yzVXrY',
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
		'person_role' => 'Husband of Former Resident',
		'video_url'   => "{$s3_base}/testimonial-husband.mp4",
		'thumbnail'   => array(
			'id'        => 0,
			'url'       => "{$s3_base}/testimonial-husband-poster.jpg",
			'alt'       => 'Husband of Former Resident',
			'title'     => 'testimonial-husband-poster',
			'filename'  => 'testimonial-husband-poster.jpg',
			'mime_type' => 'image/jpeg',
			'width'     => 0,
			'height'    => 0,
		),
	),
	array(
		'person_name' => '',
		'person_role' => 'Supervisor of Resident Services',
		'video_url'   => "{$s3_base}/testimonial-supervisor.mp4",
		'thumbnail'   => array(
			'id'        => 0,
			'url'       => "{$s3_base}/testimonial-supervisor-poster.jpg",
			'alt'       => 'Supervisor of Resident Services',
			'title'     => 'testimonial-supervisor-poster',
			'filename'  => 'testimonial-supervisor-poster.jpg',
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
echo "URL:      /tour/arden-courts-of-bath/\n";
