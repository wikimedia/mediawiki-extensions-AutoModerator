<?php
/**
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 *
 * @file
 */

namespace MediaWiki\Extension\AutoModerator;

use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\User\User;

class Util {
	/**
	 * Cribbed from AbuseFilter
	 * @todo use i18n instead of hardcoded username
	 * @return User
	 */
	public static function getUser(): User {
		$logger = LoggerFactory::getInstance( 'AutoModerator' );
		$username = 'automoderator-username';
		$systemUser = User::newSystemUser( $username, [ 'steal' => true ] );
		if ( !$systemUser ) {
			// User name is invalid. Don't throw because this is a system message, easy
			// to change and make wrong either by mistake or intentionally to break the site.
			$logger->warning(
				'The AutoModerator user\'s name is invalid. Please change it.'
			);
			// Use the default name to avoid breaking other stuff. This should have no harm,
			// aside from blocks temporarily attributed to another user.
			// Don't use the database in case the English onwiki message is broken, T284364
			$defaultName = 'automoderator-username';
			$systemUser = User::newSystemUser( $defaultName, [ 'steal' => true ] );
		}
		'@phan-var User $systemUser';
		// Promote user to 'sysop' so it doesn't look
		// like an unprivileged account is blocking users
		$userGroupManager = MediaWikiServices::getInstance()->getUserGroupManager();
		if ( !in_array( 'sysop', $userGroupManager->getUserGroups( $systemUser ) ) ) {
			$userGroupManager->addUserToGroup( $systemUser, 'sysop' );
		}
		return $systemUser;
	}
}
