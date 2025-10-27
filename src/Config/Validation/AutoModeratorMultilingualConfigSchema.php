<?php

namespace AutoModerator\Config\Validation;

use MediaWiki\Extension\CommunityConfiguration\Schema\JsonSchema;
use MediaWiki\Extension\CommunityConfiguration\Schemas\MediaWiki\MediaWikiDefinitions;

// phpcs:disable Generic.NamingConventions.UpperCaseConstantName.ClassConstantNotUpperCase
class AutoModeratorMultilingualConfigSchema extends JsonSchema {
	public const VERSION = '1.0.0';

	public const AutoModeratorMultilingualConfigEnableRevisionCheck = [
		self::TYPE => self::TYPE_BOOLEAN,
		self::DEFAULT => false,
	];

	public const AutoModeratorMultilingualConfigConfigureThreshold = [
		self::TYPE => self::TYPE_OBJECT,
		self::REQUIRED => false,
		self::DEFAULT => '',
	];

	public const AutoModeratorMultilingualConfigEnableLanguageAgnostic = [
		self::TYPE => self::TYPE_BOOLEAN,
		self::DEFAULT => false,
	];

	public const AutoModeratorMultilingualConfigCautionLevel = [
		self::TYPE => self::TYPE_STRING,
		self::REQUIRED => false,
		self::DEFAULT => "very-cautious",
		self::ENUM => [ 'very-cautious', 'cautious', 'somewhat-cautious', 'less-cautious' ]
	];

	public const AutoModeratorMultilingualConfigEnableMultilingual = [
		self::TYPE => self::TYPE_BOOLEAN,
		self::DEFAULT => false,
	];

	public const AutoModeratorMultilingualConfigMultilingualThreshold = [
		self::REQUIRED => false,
		self::TYPE => self::TYPE_STRING,
		self::DEFAULT => '',
	];

	public const AutoModeratorMultilingualConfigUseEditFlagMinor = [
		self::TYPE => self::TYPE_BOOLEAN,
		self::DEFAULT => false
	];

	public const AutoModeratorMultilingualConfigEnableBotFlag = [
		self::TYPE => self::TYPE_BOOLEAN,
		self::DEFAULT => false
	];

	public const AutoModeratorMultilingualConfigRevertTalkPageMessageEnabled = [
		self::TYPE => self::TYPE_BOOLEAN,
		self::DEFAULT => false
	];

	public const AutoModeratorMultilingualConfigRevertTalkPageMessageRegisteredUsersOnly = [
		self::TYPE => self::TYPE_BOOLEAN,
		self::DEFAULT => false
	];

	public const AutoModeratorMultilingualConfigHelpPageLink = [
		self::REQUIRED => false,
		self::REF => [ 'class' => MediaWikiDefinitions::class, 'field' => 'PageTitle' ],
		self::DEFAULT => "",
	];

	public const AutoModeratorMultilingualConfigEnableUserRevertsPerPage = [
		self::REQUIRED => true,
		self::TYPE => self::TYPE_BOOLEAN,
		self::DEFAULT => false
	];

	public const AutoModeratorMultilingualConfigUserRevertsPerPage = [
		self::REQUIRED => false,
		self::TYPE => self::TYPE_STRING,
		self::DEFAULT => '',
	];

	public const AutoModeratorMultilingualConfigSkipUserRights = [
		self::TYPE => self::TYPE_ARRAY,
		self::REQUIRED => false,
		self::DEFAULT => [ 'bot', 'autopatrol' ],
		self::ITEMS => [
			self::TYPE => self::TYPE_STRING
		],
	];

	public const AutoModeratorMultilingualConfigFalsePositivePageTitle = [
		self::REF => [ 'class' => MediaWikiDefinitions::class, 'field' => 'PageTitle' ],
		self::DEFAULT => "",
	];
}
