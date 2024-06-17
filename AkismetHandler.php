<?php

/**
 * @file plugins/generic/akismet/AkismetHandler.inc.php
 *
 * Copyright (c) University of Pittsburgh
 * Distributed under the GNU GPL v2 or later. For full terms see the LICENSE file.
 *
 * @ingroup plugins_generic_akismet
 * @brief Handles controller requests for Akismet plugin.
 */

import('classes.handler.Handler');

class AkismetHandler extends Handler {

	/**
	 * @copydoc GridHandler::initialize()
	 */
	function initialize($request, $args = null) {
		parent::initialize($request, $args);
		// Load grid locale for 'grid.user.cannotAdminister' error.
		AppLocale::requireComponents(
			LOCALE_COMPONENT_PKP_GRID
		);
	}

	/**
	 * Handle markAsSpam action
	 * @param $args array Arguments array.
	 * @param $request PKPRequest Request object.
	 */
	function markAsSpam($args, $request) {
		$user = $request->getUser();
		// Ensure that we can administer this user
		if (!isset($args['userid']) || !Validation::canAdminister($args['userid'], $user->getId())) {
			return new JSONMessage(false, __('grid.user.cannotAdminister'));
		}
		// Report the user as spam via the Plugin
		$plugin = PluginRegistry::getPlugin('generic', 'akismetplugin');
		if ($plugin->reportMissedSpamUser($args['userid'])) {
			// Successful submission deletes the local data
			$plugin->unsetAkismetData($args['userid']);
			// Notify the user
			$notificationManager = new NotificationManager();
			$notificationManager->createTrivialNotification($user->getId(), NOTIFICATION_TYPE_SUCCESS, array('contents' => __('plugins.generic.akismet.spamDetected')));
			// Refresh the grid row data.
			return DAO::getDataChangedEvent($args['userid']);
		} else {
			return new JSONMessage(false, __('plugins.generic.akismet.spamFailed'));
		}
	}
}
