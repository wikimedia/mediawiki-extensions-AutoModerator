<?php

namespace AutoModerator;

use MediaWiki\Logging\LogFormatter;

class AutoModeratorLogFormatter extends LogFormatter {
	/** @inheritDoc */
	protected function getActionMessage() {
		$params = parent::extractParameters();
		$revId = $params[3] ?? "";
		$user = $params[4] ?? "";
		$score = $params[5] ?? "";
		$actionMessage = parent::getActionMessage();
		return $actionMessage->text() . ' '
			. wfMessage( 'logentry-automoderator-revert_decision-comment',
				$revId,
				$user,
				$score
			)->parse();
	}
}
