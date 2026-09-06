<?php
/**
 * Auberge theme configuration.
 *
 * @package H3VT_Tours
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'name'        => 'Auberge',
	'description' => 'Matches the Auberge 3DVista tours: black header with orange corner arc, orange italic uppercase navigation, category gallery modals, and orange icon buttons.',
	'head_markup' => '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Montserrat:ital,wght@0,400;0,600;0,700;1,600;1,700&display=swap">',

	/*
	 * Nav categories open a gallery modal of their slides instead of a
	 * dropdown list, matching how the Auberge 3DVista tours behave.
	 */
	'nav_mode'    => 'modal',

	/*
	 * Navigation-instructions card shown when the tour loads, rebuilt as
	 * real text from the graphic the 3DVista tours open with. Newlines in
	 * the labels become line breaks.
	 */
	'welcome'     => array(
		'duration'     => 6, // seconds before it slides away on its own
		'top_label'    => "Gallery Buttons\nfor Specific Areas",
		'headline'     => 'Welcome to Our Virtual Tour',
		'subheadline'  => 'The Tour Will Autorun at the Start',
		'bottom_label' => "Use the Navigation Buttons\nBelow to Access more Features",
	),
);
