<?php

namespace AutoModerator;

use MediaWiki\Logging\LogFormatter;
use MediaWiki\Message\Message;
use MediaWiki\SpecialPage\SpecialPage;

class AutoModeratorLogFormatter extends LogFormatter {
	/** @inheritDoc */
	protected function getMessageParameters() {
		$params = parent::getMessageParameters();

		$entryParams = $this->entry->getParameters();

		$revId = $entryParams['4::revId'];
		$diffTitle = SpecialPage::getTitleFor( 'Diff', (string)$revId );
		$params[3] = Message::rawParam( $this->makePageLink( $diffTitle, [], (string)$revId ) );

		$username = $entryParams['5::user'];
		$params[4] = $this->formatParameterValue( 'user-link', $username );

		return $params;
	}
}
