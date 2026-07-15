<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\AutoModerator\Hooks;

use MediaWiki\Config\Config;
use MediaWiki\Extension\AutoModerator\TalkPageMessageSender;
use MediaWiki\Extension\AutoModerator\Util;
use MediaWiki\Page\Hook\RollbackCompleteHook;
use MediaWiki\Page\WikiPage;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\User\UserGroupManager;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserIdentityUtils;

readonly class RollbackCompleteHookHandler implements RollbackCompleteHook {
	public function __construct(
		private UserGroupManager $userGroupManager,
		private Config $config,
		private TalkPageMessageSender $talkPageMessageSender,
		private UserIdentityUtils $userIdentityUtils,
	) {
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
					$autoModeratorUser
				);
			}
		}
	}

	private function shouldSendTalkPageMessage( RevisionRecord $current ): bool {
		$isMessageEnabled = Util::getRevertTalkPageMessageEnabled( $this->config );
		$isMessageRegisteredUsersOnly = Util::getRevertTalkPageMessageRegisteredUsersOnly( $this->config );
		$isCurrentUserNamed = $this->userIdentityUtils->isNamed( $current->getUser() );

		return $isMessageEnabled && ( $isCurrentUserNamed || !$isMessageRegisteredUsersOnly );
	}
}
