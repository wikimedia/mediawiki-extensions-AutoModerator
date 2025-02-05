<?php

namespace AutoModerator\Config\Validation;

use MediaWiki\Extension\CommunityConfiguration\Schema\JsonSchema;
use MediaWiki\Extension\CommunityConfiguration\Schemas\MediaWiki\MediaWikiDefinitions;

// phpcs:disable Generic.NamingConventions.UpperCaseConstantName.ClassConstantNotUpperCase
class AutoModeratorConfigSchema extends JsonSchema {
	public const VERSION = '1.0.0';

	public const AutoModeratorEnableRevisionCheck = [
		self::TYPE => self::TYPE_BOOLEAN,
		self::DEFAULT => false,
	];

	public const AutoModeratorCautionLevel = [
		self::TYPE => self::TYPE_STRING,
		self::REQUIRED => false,
		self::DEFAULT => "very-cautious",
		self::ENUM => [ 'very-cautious', 'cautious', 'somewhat-cautious', 'less-cautious' ]
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

	public const AutoModeratorHelpPageLink = [
		self::REQUIRED => false,
		self::REF => [ 'class' => MediaWikiDefinitions::class, 'field' => 'PageTitle' ],
		self::DEFAULT => "",
	];

	public const AutoModeratorEnableUserRevertsPerPage = [
		self::REQUIRED => true,
		self::TYPE => self::TYPE_BOOLEAN,
		self::DEFAULT => false
	];

	public const AutoModeratorUserRevertsPerPage = [
		self::REQUIRED => false,
		self::TYPE => self::TYPE_STRING,
		self::DEFAULT => '',
	];

	public const AutoModeratorSkipUserRights = [
		self::TYPE => self::TYPE_ARRAY,
		self::REQUIRED => false,
		self::DEFAULT => [ 'bot', 'autopatrol' ],
		self::ITEMS => [
			self::TYPE => self::TYPE_STRING
		],
	];

	public const AutoModeratorFalsePositivePageTitle = [
		self::REF => [ 'class' => MediaWikiDefinitions::class, 'field' => 'PageTitle' ],
		self::DEFAULT => "",
	];
}
