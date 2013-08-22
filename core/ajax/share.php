<?php
/**
* ownCloud
*
* @author Michael Gapczynski
* @copyright 2012 Michael Gapczynski mtgap@owncloud.com
*
* This library is free software; you can redistribute it and/or
* modify it under the terms of the GNU AFFERO GENERAL PUBLIC LICENSE
* License as published by the Free Software Foundation; either
* version 3 of the License, or any later version.
*
* This library is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
* GNU AFFERO GENERAL PUBLIC LICENSE for more details.
*
* You should have received a copy of the GNU Affero General Public
* License along with this library.  If not, see <http://www.gnu.org/licenses/>.
*/

OC_JSON::checkLoggedIn();
OCP\JSON::callCheck();
OC_App::loadApps();

$shareManager = OCP\Share::getShareManager();
$defaults = new \OCP\Defaults();

if (isset($_POST['action']) && isset($_POST['itemType']) && isset($_POST['itemSource'])) {
	switch ($_POST['action']) {
		case 'share':
			if (isset($_POST['shareType']) && isset($_POST['shareWith']) && isset($_POST['permissions'])) {
				try {
					$share = new \OC\Share\Share();
					$shareType = $_POST['shareType'];
					settype($shareType, 'int');
					if ($shareType === \OCP\Share::SHARE_TYPE_USER) {
						$share->setShareTypeId('user');
					} else if ($shareType === \OCP\Share::SHARE_TYPE_GROUP) {
						$share->setShareTypeId('group');
					} else if ($shareType === \OCP\Share::SHARE_TYPE_LINK) {
						$share->setShareTypeId('link');
					}
					$shareWith = $_POST['shareWith'];
					if ($shareType === \OCP\Share::SHARE_TYPE_LINK && $shareWith === '') {
						$shareWith = null;
					}
					$share->setShareOwner(\OCP\User::getUser());
					$share->setShareWith($shareWith);
					$share->setItemType($_POST['itemType']);
					$share->setItemSource($_POST['itemSource']);
					$share->setPermissions((int)$_POST['permissions']);
					$share = $shareManager->share($share);
					$token = $share->getToken();
					if (isset($token)) {
						OC_JSON::success(array('data' => array('token' => $token)));
					} else {
						OC_JSON::success();
					}
				} catch (Exception $exception) {
					OC_JSON::error(array('data' => array('message' => $exception->getMessage())));
				}
			}
			break;
		case 'unshare':
			if (isset($_POST['shareType']) && isset($_POST['shareWith'])) {
				$shareType = $_POST['shareType'];
				settype($shareType, 'int');
				if ($shareType === \OCP\Share::SHARE_TYPE_USER) {
					$shareType = 'user';
				} else if ($shareType === \OCP\Share::SHARE_TYPE_GROUP) {
					$shareType ='group';
				} else if ($shareType === \OCP\Share::SHARE_TYPE_LINK) {
					$shareType = 'link';
				}
				$filter = array(
					'shareOwner' => \OCP\User::getUser(),
					'shareWith' => $_POST['shareWith'],
					'shareTypeId' => $shareType,
					'itemSource' => $_POST['itemSource'],
				);
				try {
					$share = $shareManager->getShares($_POST['itemType'], $filter, 1);
					if (!empty($share)) {
						$share = reset($share);
						$shareManager->unshare($share);
					}
				} catch (Exception $exception) {
					OC_JSON::error();
				}
				OC_JSON::success();
			}
			break;
		case 'setPermissions':
			if (isset($_POST['shareType']) && isset($_POST['shareWith']) && isset($_POST['permissions'])) {
				$shareType = $_POST['shareType'];
				settype($shareType, 'int');
				if ($shareType === \OCP\Share::SHARE_TYPE_USER) {
					$shareType = 'user';
				} else if ($shareType === \OCP\Share::SHARE_TYPE_GROUP) {
					$shareType ='group';
				} else if ($shareType === \OCP\Share::SHARE_TYPE_LINK) {
					$shareType = 'link';
				}
				$filter = array(
					'shareOwner' => \OCP\User::getUser(),
					'shareWith' => $_POST['shareWith'],
					'shareTypeId' => $shareType,
					'itemSource' => $_POST['itemSource'],
				);
				try {
					$share = $shareManager->getShares($_POST['itemType'], $filter, 1);
					if (!empty($share)) {
						$share = reset($share);
						$share->setPermissions((int)$_POST['permissions']);
						$shareManager->update($share);
					}
				} catch (Exception $exception) {
					OC_JSON::error();
				}
				OC_JSON::success();
			}
			break;
		case 'setExpirationDate':
			if (isset($_POST['date'])) {
				$return = OCP\Share::setExpirationDate($_POST['itemType'], $_POST['itemSource'], $_POST['date']);
				($return) ? OC_JSON::success() : OC_JSON::error();
			}
			break;
		case 'informRecipients':
			// enable l10n support
			$l = OC_L10N::get('core');

			$shareType = (int) $_POST['shareType'];
			$itemType = $_POST['itemType'];
			$recipient = $_POST['recipient'];
			$from = \OCP\Util::getDefaultEmailAddress('sharing-noreply');
			$subject = $defaults->getShareNotificationSubject($itemType);

			$noMail = array();
			$recipientList = array();

			if ($shareType === \OCP\Share::SHARE_TYPE_GROUP) {
				$users = \OC_Group::usersInGroup($recipient);
				foreach ($users as $user) {
					$email = OC_Preferences::getValue($user, 'settings', 'email', '');
					if ($email !== '') {
						$recipientList[] = array(
							'email' => $email,
							'displayName' => \OCP\User::getDisplayName($user),
							'uid' => $user,
						);
					} else {
						$noMail[] = \OCP\User::getDisplayName($user);
					}
				}
			} else {  // shared to a single user
				$email = OC_Preferences::getValue($recipient, 'settings', 'email', '');
				if ($email !== '') {
					$recipientList[] = array(
						'email' => $email,
						'displayName' => \OCP\User::getDisplayName($recipient),
						'uid' => $recipient,
					);
				} else {
					$noMail[] = \OCP\User::getDisplayName($recipient);
				}
			}

			// send mail to all recipients with an email address
			foreach ($recipientList as $recipient) {
				//get correct target folder name
				if ($itemType === 'folder') {
					// TODO get user specific folder name
					$foldername = "testfolder";
					$filename = $foldername;
				} else {
					// if it is a file we can just link to the Shared folder,
					// that's the place where the user will find the file
					$foldername = "/Shared";
					//TODO get for every user the correct filename name
					$filename = "foo.txt";
				}

				$url = \OCP\Util::linkToAbsolute('files', 'index.php', array("dir" => $foldername));
				$text = $defaults->getShareNotificationText(\OCP\User::getDisplayName(), $filename, $itemType, $url);

				try {
					OCP\Util::sendMail(
							$recipient['email'],
							$recipient['displayName'],
							$subject,
							$text,
							$from,
							\OCP\User::getDisplayName()
					);
				} catch (Exception $exception) {
					$noMail[] = \OCP\User::getDisplayName($recipient['displayName']);
				}
			}

			if (empty($noMail)) {
				OCP\JSON::success();
			} else {
				OCP\JSON::error(array('data' => array('message' => $l->t("Couldn't send mail to following users: %s ", implode(', ', $noMail)))));
			}
			break;
		case 'email':
			// read post variables
			$user = OCP\USER::getUser();
			$displayName = OCP\User::getDisplayName();
			$type = $_POST['itemType'];
			$link = $_POST['link'];
			$file = $_POST['file'];
			$to_address = $_POST['toaddress'];

			// enable l10n support
			$l = OC_L10N::get('core');

			// setup the email
			$subject = (string)$l->t('%s shared »%s« with you', array($displayName, $file));

			$content = new OC_Template("core", "mail", "");
			$content->assign ('link', $link);
			$content->assign ('type', $type);
			$content->assign ('user_displayname', $displayName);
			$content->assign ('filename', $file);
			$text = $content->fetchPage();

			$content = new OC_Template("core", "altmail", "");
			$content->assign ('link', $link);
			$content->assign ('type', $type);
			$content->assign ('user_displayname', $displayName);
			$content->assign ('filename', $file);
			$alttext = $content->fetchPage();

			$default_from = OCP\Util::getDefaultEmailAddress('sharing-noreply');
			$from_address = OCP\Config::getUserValue($user, 'settings', 'email', $default_from );

			// send it out now
			try {
				OCP\Util::sendMail($to_address, $to_address, $subject, $text, $from_address, $displayName, 1, $alttext);
				OCP\JSON::success();
			} catch (Exception $exception) {
				OCP\JSON::error(array('data' => array('message' => OC_Util::sanitizeHTML($exception->getMessage()))));
			}
			break;
	}
} else if (isset($_GET['fetch'])) {
	switch ($_GET['fetch']) {
		case 'getItemsSharedStatuses':
			if (isset($_GET['itemType'])) {
				// $return = OCP\Share::getItemsShared($_GET['itemType'], OCP\Share::FORMAT_STATUSES);
				$return = array();
				is_array($return) ? OC_JSON::success(array('data' => $return)) : OC_JSON::error();
			}
			break;
		case 'getItem':
			if (isset($_GET['itemType'])
				&& isset($_GET['itemSource'])
				&& isset($_GET['checkReshare'])
				&& isset($_GET['checkShares'])) {
				// if ($_GET['checkReshare'] == 'true') {
				// 	$reshare = OCP\Share::getItemSharedWithBySource(
				// 		$_GET['itemType'],
				// 		$_GET['itemSource'],
				// 		OCP\Share::FORMAT_NONE,
				// 		null,
				// 		true
				// 	);
				// } else {
					$reshare = array();
				// }
				if ($_GET['checkShares'] == 'true') {
					$filter = array(
						'shareOwner' => \OCP\User::getUser(),
						'itemSource' => $_GET['itemSource'],
					);
					$result = $shareManager->getShares($_GET['itemType'], $filter);
					$shares = array();
					foreach ($result as $share) {
						$shareTypeId = $share->getShareTypeId();
						if ($shareTypeId === 'user') {
							$shareTypeId = \OCP\Share::SHARE_TYPE_USER;
						} else if ($shareTypeId === 'group') {
							$shareTypeId = OCP\Share::SHARE_TYPE_GROUP;
						} else if ($shareTypeId === 'link') {
							$shareTypeId = OCP\Share::SHARE_TYPE_LINK;
						}
						$shares[$share->getId()] = array(
							'share_type' => $shareTypeId,
							'uid_owner' => $share->getShareOwner(),
							'share_with' => $share->getShareWith(),
							'permissions' => $share->getPermissions(),
							'share_with_displayname' => $share->getShareWithDisplayName(),
							'displayname_owner' => $share->getShareOwnerDisplayName(),
						);
					}
				} else {
					$shares = array();
				}
				OC_JSON::success(array('data' => array('reshare' => $reshare, 'shares' => $shares)));
			}
			break;
		case 'getShareWith':
			if (isset($_GET['search'])) {
				$shareWiths = array();
				$shareOwner = \OCP\User::getUser();
				$shareBackend = $shareManager->getShareBackend('file');
				$shareTypes = $shareBackend->getShareTypes();
				foreach ($shareTypes as $shareType) {
					$shareTypeId = $shareType->getId();
					if ($shareTypeId === 'user') {
						$shareTypeId = \OCP\Share::SHARE_TYPE_USER;
					} else if ($shareTypeId === 'group') {
						$shareTypeId = OCP\Share::SHARE_TYPE_GROUP;
					}
					$result = $shareType->searchForPotentialShareWiths($shareOwner, $_GET['search'], 10, null);
					foreach ($result as $shareWith) {
						$shareWiths[] = array(
							'label' => $shareWith['shareWithDisplayName'],
							'value' => array(
								'shareType' => $shareTypeId,
								'shareWith' => $shareWith['shareWith'],
							),
						);
						if (isset($limit)) {
							$limit--;
							if ($limit === 0) {
								break 2;
							}
						}
						if (isset($offset) && $offset > 0) {
							$offset--;
						}
					}
				}
				OC_JSON::success(array('data' => $shareWiths));
			}
			break;
	}
}
