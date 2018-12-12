<?php

/**
 * @file plugins/generic/akismet/markSpamUser.php
 *
 * Copyright (c) University of Pittsburgh
 * Distributed under the GNU GPL v2 or later. For full terms see the LICENSE file.
 *
 * @class markSpamUser
 * @ingroup plugins_generic_akismet
 *
 * @brief CLI tool for marking a user as missed spam by username within Akismet.
 */

require(dirname(dirname(dirname(dirname(__FILE__)))) . '/tools/bootstrap.inc.php');

class markSpamUser extends CommandLineTool {

	/** @var $username string */
	var $username;

	/**
	 * Constructor.
	 * @param $argv array command-line arguments
	 */
	function markSpamUser($argv = array()) {
		parent::__construct($argv);

		if (!isset($this->argv[0])) {
			$this->usage();
			exit(1);
		}

		$this->username = $this->argv[0];
	}

	/**
	 * Print command usage information.
	 */
	function usage() {
		echo "Mark Spam User tool\n"
			. "This tool will mark a user as missed spam in Akismet.\n\n"
			. "Usage: {$this->scriptName} [username]\n"
			. "username       The user to submit to Akismet.\n";
	}

	/**
	 * Execute the merge users command.
	 */
	function execute() {
		$plugin = PluginRegistry::getPlugin('generic', 'akismetplugin');
		$userDao = DAORegistry::getDAO('UserDAO');

		$user = $userDao->getByUsername($this->username);

		$userId = isset($user) ? $user->getId() : null;
		if (empty($userId)) {
			printf("Error: '%s' is not a valid username.\n",
				$this->username);
			exit;
		}

		
		if (!$plugin->getAkismetData($userId)) {
			printf("Error: '%s' is unknown to Akismet.\n",
				$this->username);
			exit;
		}

		// User exists and has Akismet data, proceed.
		if ($plugin->reportMissedSpamUser($userId)) {
			$plugin->unsetAkismetData($userId);
			printf("Reported as missed spam: '%s'.\n",
				$this->username
			);
		} else {
			printf("Failed to report as missed spam: '%s'.\n",
				$this->username
			);
		}
	}
}

$tool = new markSpamUser(isset($argv) ? $argv : array());
$tool->execute();
