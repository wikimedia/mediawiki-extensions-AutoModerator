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

namespace AutoModerator;

use MediaWiki\MediaWikiServices;
use MediaWiki\User\User;
use UnexpectedValueException;

class Util {
	/**
	 * Get a user to perform moderation actions.
	 * @return User
	 */
	public static function getAutoModeratorUser(): User {
		$config = MediaWikiServices::getInstance()->getMainConfig();
		$username = $config->get( 'AutoModeratorUsername' );
		$autoModeratorUser = User::newSystemUser( $username, [ 'steal' => true ] );
		'@phan-var User $autoModeratorUser';
		if ( !$autoModeratorUser ) {
			throw new UnexpectedValueException(
				"{$username} is invalid. Please change it."
			);
		}
		// Promote user to 'sysop' so it doesn't look
		// like an unprivileged account is blocking users
		$userGroupManager = MediaWikiServices::getInstance()->getUserGroupManager();
		if ( !in_array( 'sysop', $userGroupManager->getUserGroups( $autoModeratorUser ) ) ) {
			$userGroupManager->addUserToGroup( $autoModeratorUser, 'sysop' );
		}
		return $autoModeratorUser;
	}
}
