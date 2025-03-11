<?php

namespace AutoModerator\Hooks;

use AutoModerator\TalkPageMessageSender;
use AutoModerator\Util;
use MediaWiki\Config\Config;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Page\Hook\RollbackCompleteHook;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\User\UserGroupManager;
use MediaWiki\User\UserIdentity;
use WikiPage;

class RollbackCompleteHookHandler implements RollbackCompleteHook {
	private Config $config;

	private UserGroupManager $userGroupManager;

	private Config $wikiConfig;

	private TalkPageMessageSender $talkPageMessageSender;

	/**
	 * @param Config $wikiConfig
	 * @param UserGroupManager $userGroupManager
	 * @param Config $config
	 * @param TalkPageMessageSender $talkPageMessageSender
	 */
	public function __construct(
		Config $wikiConfig,
		UserGroupManager $userGroupManager,
		Config $config,
		TalkPageMessageSender $talkPageMessageSender
	) {
		$this->wikiConfig = $wikiConfig;
		$this->userGroupManager = $userGroupManager;
		$this->config = $config;
		$this->talkPageMessageSender = $talkPageMessageSender;
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
			if ( $this->wikiConfig->get( 'AutoModeratorRevertTalkPageMessageEnabled' ) ) {
				$this->talkPageMessageSender->insertAutoModeratorSendRevertTalkPageMsgJob(
						$wikiPage->getTitle(),
						$revId,
						$rollbackRevId,
						$autoModeratorUser,
						LoggerFactory::getInstance( 'AutoModerator' ) );
			}
		}
	}
}
