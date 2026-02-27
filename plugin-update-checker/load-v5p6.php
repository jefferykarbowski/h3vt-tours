<?php

namespace YahnisElsts\PluginUpdateChecker\v5p6;

use YahnisElsts\PluginUpdateChecker\v5\PucFactory as MajorFactory;
use YahnisElsts\PluginUpdateChecker\v5p6\PucFactory as MinorFactory;

require __DIR__ . '/Puc/v5p6/Autoloader.php';
new Autoloader();

// Explicitly load Parsedown to ensure it's available
// Try multiple methods to ensure Parsedown is loaded
if ( !class_exists('Parsedown', false) ) {
	$parsedownPath = __DIR__ . '/vendor/Parsedown.php';
	if ( file_exists($parsedownPath) ) {
		require_once $parsedownPath;
	} else {
		// Fallback: Try to load ParsedownModern directly
		$parsedownModernPath = __DIR__ . '/vendor/ParsedownModern.php';
		if ( file_exists($parsedownModernPath) ) {
			require_once $parsedownModernPath;
		}
	}
}

require __DIR__ . '/Puc/v5p6/PucFactory.php';
require __DIR__ . '/Puc/v5/PucFactory.php';

//Register classes defined in this version with the factory.
foreach (
	array(
		'Plugin\\UpdateChecker' => Plugin\UpdateChecker::class,
		'Theme\\UpdateChecker'  => Theme\UpdateChecker::class,

		'Vcs\\PluginUpdateChecker' => Vcs\PluginUpdateChecker::class,
		'Vcs\\ThemeUpdateChecker'  => Vcs\ThemeUpdateChecker::class,

		'GitHubApi'    => Vcs\GitHubApi::class,
		'BitBucketApi' => Vcs\BitBucketApi::class,
		'GitLabApi'    => Vcs\GitLabApi::class,
	)
	as $pucGeneralClass => $pucVersionedClass
) {
	MajorFactory::addVersion($pucGeneralClass, $pucVersionedClass, '5.6');
	//Also add it to the minor-version factory in case the major-version factory
	//was already defined by another, older version of the update checker.
	MinorFactory::addVersion($pucGeneralClass, $pucVersionedClass, '5.6');
}

