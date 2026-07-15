<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\AutoModerator\Config\Validation;

use MediaWiki\Extension\CommunityConfiguration\Schema\JsonSchema;
use MediaWiki\Extension\CommunityConfiguration\Schemas\MediaWiki\MediaWikiDefinitions;

// phpcs:disable Generic.NamingConventions.UpperCaseConstantName.ClassConstantNotUpperCase
class AutoModeratorMultilingualConfigSchema extends JsonSchema {
	public const string VERSION = '1.0.0';

	public const array AutoModeratorMultilingualConfigEnableRevisionCheck = [
		self::TYPE => self::TYPE_BOOLEAN,
		self::DEFAULT => false,
	];

	public const array AutoModeratorMultilingualEnableLogOnlyMode = [
		self::TYPE => self::TYPE_BOOLEAN,
		self::DEFAULT => false,
	];

	public const array AutoModeratorMultilingualConfigConfigureThreshold = [
		self::TYPE => self::TYPE_OBJECT,
		self::REQUIRED => false,
		self::DEFAULT => '',
	];

	public const array AutoModeratorMultilingualConfigEnableLanguageAgnostic = [
		self::TYPE => self::TYPE_BOOLEAN,
		self::DEFAULT => false,
	];

	public const array AutoModeratorMultilingualConfigCautionLevel = [
		self::TYPE => self::TYPE_STRING,
		self::REQUIRED => false,
		self::DEFAULT => "very-cautious",
		self::ENUM => [ 'very-cautious', 'cautious', 'somewhat-cautious', 'less-cautious' ]
	];

	public const array AutoModeratorMultilingualConfigEnableMultilingual = [
		self::TYPE => self::TYPE_BOOLEAN,
		self::DEFAULT => false,
	];

	public const array AutoModeratorMultilingualConfigMultilingualThreshold = [
		self::REQUIRED => false,
		self::TYPE => self::TYPE_STRING,
		self::DEFAULT => '',
	];

	public const array AutoModeratorMultilingualConfigUseEditFlagMinor = [
		self::TYPE => self::TYPE_BOOLEAN,
		self::DEFAULT => false
	];

	public const array AutoModeratorMultilingualConfigEnableBotFlag = [
		self::TYPE => self::TYPE_BOOLEAN,
		self::DEFAULT => false
	];

	public const array AutoModeratorMultilingualConfigRevertTalkPageMessageEnabled = [
		self::TYPE => self::TYPE_BOOLEAN,
		self::DEFAULT => false
	];

	public const array AutoModeratorMultilingualConfigRevertTalkPageMessageRegisteredUsersOnly = [
		self::TYPE => self::TYPE_BOOLEAN,
		self::DEFAULT => false
	];

	public const array AutoModeratorMultilingualConfigHelpPageLink = [
		self::REQUIRED => false,
		self::REF => [ 'class' => MediaWikiDefinitions::class, 'field' => 'PageTitle' ],
		self::DEFAULT => '',
	];

	public const array AutoModeratorMultilingualConfigEnableUserRevertsPerPage = [
		self::REQUIRED => true,
		self::TYPE => self::TYPE_BOOLEAN,
		self::DEFAULT => false
	];

	public const array AutoModeratorMultilingualConfigUserRevertsPerPage = [
		self::REQUIRED => false,
		self::TYPE => self::TYPE_STRING,
		self::DEFAULT => '',
	];

	public const array AutoModeratorMultilingualConfigSkipUserRights = [
		self::TYPE => self::TYPE_ARRAY,
		self::REQUIRED => false,
		self::DEFAULT => [ 'bot', 'autopatrol' ],
		self::ITEMS => [
			self::TYPE => self::TYPE_STRING
		],
	];

	public const array AutoModeratorMultilingualConfigFalsePositivePageTitle = [
		self::REF => [ 'class' => MediaWikiDefinitions::class, 'field' => 'PageTitle' ],
		self::DEFAULT => '',
	];
}
