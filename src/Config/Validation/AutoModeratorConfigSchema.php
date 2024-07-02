<?php

namespace AutoModerator\Config\Validation;

use MediaWiki\Extension\CommunityConfiguration\Schema\JsonSchema;
use MediaWiki\Extension\CommunityConfiguration\Schemas\MediaWiki\MediaWikiDefinitions;

// phpcs:disable Generic.NamingConventions.UpperCaseConstantName.ClassConstantNotUpperCase
class AutoModeratorConfigSchema extends JsonSchema {
	public const AutoModeratorEnableRevisionCheck = [
		self::TYPE => self::TYPE_BOOLEAN,
		self::DEFAULT => false,
	];

	public const AutoModeratorUseEditFlagMinor = [
		self::TYPE => self::TYPE_BOOLEAN,
		self::DEFAULT => false
	];

	public const AutoModeratorEnableBotFlag = [
		self::TYPE => self::TYPE_BOOLEAN,
		self::DEFAULT => false
	];

	public const AutoModeratorRevertTalkPageMessageEnabled = [
		self::TYPE => self::TYPE_BOOLEAN,
		self::DEFAULT => false
	];

	public const AutoModeratorSkipUserRights = [
		self::TYPE => self::TYPE_ARRAY,
		self::REQUIRED => false,
		self::DEFAULT => [ 'bot', 'autopatrol' ],
		self::ITEMS => [
			self::ENUM => [ 'bot', 'autopatrol' ],
			self::TYPE => self::TYPE_STRING
		],
	];

	public const AutoModeratorFalsePositivePageTitle = [
		self::REF => [ 'class' => MediaWikiDefinitions::class, 'field' => 'PageTitle' ],
		self::DEFAULT => "",
	];

}
