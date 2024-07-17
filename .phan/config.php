<?php

$cfg = require __DIR__ . '/../vendor/mediawiki/mediawiki-phan-config/src/config.php';
$cfg['exclude_file_list'] = array_merge(
	$cfg['exclude_file_list'],
	[ "src/Config/Validation/AutoModeratorConfigSchema.php" ] );
$cfg['directory_list'] = array_merge(
	$cfg['directory_list'],
	[
		'../../extensions/ORES',
	]
);
$cfg['exclude_analysis_directory_list'] = array_merge(
	$cfg['exclude_analysis_directory_list'],
	[
		'../../extensions/ORES',
	]
);
return $cfg;
