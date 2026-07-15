<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\AutoModerator\Config\Validation;

use MediaWiki\Extension\CommunityConfiguration\Schema\JsonSchema;
use MediaWiki\Extension\CommunityConfiguration\Schemas\MediaWiki\MediaWikiDefinitions;

// phpcs:disable Generic.NamingConventions.UpperCaseConstantName.ClassConstantNotUpperCase
class AutoModeratorConfigSchema extends JsonSchema {
	public const string VERSION = '1.0.0';

	public const array AutoModeratorEnableRevisionCheck = [
		self::TYPE => self::TYPE_BOOLEAN,
		self::DEFAULT => false,
	];

	public const array AutoModeratorEnableLogOnlyMode = [
		self::TYPE => self::TYPE_BOOLEAN,
		self::DEFAULT => false,
	];

	public const array AutoModeratorCautionLevel = [
		self::TYPE => self::TYPE_STRING,
		self::REQUIRED => false,
		self::DEFAULT => "very-cautious",
		self::ENUM => [ 'very-cautious', 'cautious', 'somewhat-cautious', 'less-cautious' ]
	];

	public const array AutoModeratorUseEditFlagMinor = [
		self::TYPE => self::TYPE_BOOLEAN,
		self::DEFAULT => false
	];

	public const array AutoModeratorEnableBotFlag = [
		self::TYPE => self::TYPE_BOOLEAN,
		self::DEFAULT => false
	];

	public const array AutoModeratorRevertTalkPageMessageEnabled = [
		self::TYPE => self::TYPE_BOOLEAN,
		self::DEFAULT => false
	];

	public const array AutoModeratorRevertTalkPageMessageRegisteredUsersOnly = [
		self::TYPE => self::TYPE_BOOLEAN,
		self::DEFAULT => false
	];

	public const array AutoModeratorHelpPageLink = [
		self::REQUIRED => false,
		self::REF => [ 'class' => MediaWikiDefinitions::class, 'field' => 'PageTitle' ],
		self::DEFAULT => '',
	];

	public const array AutoModeratorEnableUserRevertsPerPage = [
		self::REQUIRED => true,
		self::TYPE => self::TYPE_BOOLEAN,
		self::DEFAULT => false
	];

	public const array AutoModeratorUserRevertsPerPage = [
		self::REQUIRED => false,
		self::TYPE => self::TYPE_STRING,
		self::DEFAULT => '',
	];

	public const array AutoModeratorSkipUserRights = [
		self::TYPE => self::TYPE_ARRAY,
		self::REQUIRED => false,
		self::DEFAULT => [ 'bot', 'autopatrol' ],
		self::ITEMS => [
			self::TYPE => self::TYPE_STRING
		],
	];

	public const array AutoModeratorFalsePositivePageTitle = [
		self::REF => [ 'class' => MediaWikiDefinitions::class, 'field' => 'PageTitle' ],
		self::DEFAULT => '',
	];
}
