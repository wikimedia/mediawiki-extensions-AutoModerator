<?php

$cfg = require __DIR__ . '/../vendor/mediawiki/mediawiki-phan-config/src/config.php';
$cfg['exclude_file_list'] = array_merge(
	$cfg['exclude_file_list'],
	[
		"src/Config/Validation/AutoModeratorConfigSchema.php",
		"src/Config/Validation/AutoModeratorMultilingualConfigSchema.php"
	] );
$cfg['directory_list'] = array_merge(
	$cfg['directory_list'],
	[
		'../../extensions/ORES',
		'../../extensions/DiscussionTools',
	]
);
$cfg['exclude_analysis_directory_list'] = array_merge(
	$cfg['exclude_analysis_directory_list'],
	[
		'../../extensions/ORES',
		'../../extensions/DiscussionTools',
	]
);
return $cfg;
