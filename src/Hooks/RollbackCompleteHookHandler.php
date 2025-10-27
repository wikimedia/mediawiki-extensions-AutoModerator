<?php

namespace AutoModerator\Hooks;

use AutoModerator\TalkPageMessageSender;
use AutoModerator\Util;
use MediaWiki\Config\Config;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Page\Hook\RollbackCompleteHook;
use MediaWiki\Page\WikiPage;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\User\UserGroupManager;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserIdentityUtils;

class RollbackCompleteHookHandler implements RollbackCompleteHook {
	private Config $config;

	private UserGroupManager $userGroupManager;

	private Config $wikiConfig;

	private TalkPageMessageSender $talkPageMessageSender;

	private UserIdentityUtils $userIdentityUtils;

	/**
	 * @param Config $wikiConfig
	 * @param UserGroupManager $userGroupManager
	 * @param Config $config
	 * @param TalkPageMessageSender $talkPageMessageSender
	 * @param UserIdentityUtils $userIdentityUtils
	 */
	public function __construct(
		Config $wikiConfig,
		UserGroupManager $userGroupManager,
		Config $config,
		TalkPageMessageSender $talkPageMessageSender,
		UserIdentityUtils $userIdentityUtils
	) {
		$this->wikiConfig = $wikiConfig;
		$this->userGroupManager = $userGroupManager;
		$this->config = $config;
		$this->talkPageMessageSender = $talkPageMessageSender;
		$this->userIdentityUtils = $userIdentityUtils;
	}

	/**
	 * @param WikiPage $wikiPage
	 * @param UserIdentity $user
	 * @param RevisionRecord $revision
	 * @param RevisionRecord $current
	 */
	public function onRollbackComplete( $wikiPage, $user, $revision, $current ) {
		$autoModeratorUser = Util::getAutoModeratorUser( $this->config, $this->userGroupManager );
		$revId = $current->getId();
		$rollbackRevId = $wikiPage->getRevisionRecord()->getId();
		if ( $autoModeratorUser->getId() === $user->getId() ) {
			if ( $this->shouldSendTalkPageMessage( $current ) ) {
				$this->talkPageMessageSender->insertAutoModeratorSendRevertTalkPageMsgJob(
						$wikiPage->getTitle(),
						$revId,
						$rollbackRevId,
						$autoModeratorUser,
						LoggerFactory::getInstance( 'AutoModerator' ) );
			}
		}
	}

	private function shouldSendTalkPageMessage( RevisionRecord $current ): bool {
		$isMultilingualRevertRiskEnabled = Util::isWikiMultilingual( $this->config );
		$isMessageEnabled = $isMultilingualRevertRiskEnabled ?
			$this->wikiConfig->get( 'AutoModeratorMultilingualConfigRevertTalkPageMessageEnabled' ) :
			$this->wikiConfig->get( 'AutoModeratorRevertTalkPageMessageEnabled' );
		$isMessageRegisteredUsersOnly = $isMultilingualRevertRiskEnabled ?
			$this->wikiConfig->get( 'AutoModeratorMultilingualConfigRevertTalkPageMessageRegisteredUsersOnly' ) :
			$this->wikiConfig->get( 'AutoModeratorRevertTalkPageMessageRegisteredUsersOnly' );
		$isCurrentUserNamed = $this->userIdentityUtils->isNamed( $current->getUser() );

		return $isMessageEnabled && ( $isCurrentUserNamed || !$isMessageRegisteredUsersOnly );
	}
}
