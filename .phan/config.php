<?php

$cfg = require __DIR__ . '/../vendor/mediawiki/mediawiki-phan-config/src/config.php';
$cfg['exclude_file_list'] = array_merge(
	$cfg['exclude_file_list'],
	[ "src/Config/Validation/AutoModeratorConfigSchema.php" ] );
return $cfg;
