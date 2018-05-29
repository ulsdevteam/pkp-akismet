<?php

/**
 * @file plugins/generic/akismet/AkismetPlugin.inc.php
 *
 * Copyright (c) 2018 University of Pittsburgh
 * Distributed under the GNU GPL v2 or later. For full terms see the LICENSE file.
 *
 * @class AkismetPlugin
 * @ingroup plugins_generic_akismet
 *
 * @brief Akismet plugin class
 */

import('lib.pkp.classes.plugins.GenericPlugin');

class AkismetPlugin extends GenericPlugin {
	
	/**
	 * @var requiredFields array Required Akismet API fields
	 */
	var $requiredFields = array('blog', 'user_ip', 'user_agent');

	/**
	 * @var dataUserSetting string Name of User Setting to store Akismet data
	 */
	var $dataUserSetting = 'submittedData';

	/**
	 * Called as a plugin is registered to the registry
	 * @param $category String Name of category plugin was registered to
	 * @return boolean True iff plugin initialized successfully; if false,
	 * 	the plugin will not be registered.
	 */
	function register($category, $path) {
		$success = parent::register($category, $path);
		if (!Config::getVar('general', 'installed') || defined('RUNNING_UPGRADE')) return true;
		if ($success && $this->getEnabled()) {

			// Enable Akismet anti-spam check of new registrations
			HookRegistry::register('registrationform::validate', array(&$this, 'checkAkismet'));
			HookRegistry::register('commentform::validate', array(&$this, 'checkAkismet'));
			HookRegistry::register('registrationform::execute', array(&$this, 'storeAkismetData'));
			HookRegistry::register('userdao::getAdditionalFieldNames', array(&$this, 'addAkismetSetting'));
			// Add a link to mark a missed spam user
			HookRegistry::register('TemplateManager::display', array($this, 'handleTemplateDisplay'));
			}
		return $success;
	}
	
	/**
	 * Add the Akismet data to the User DAO
	 * @see DAO::getAddtionalFieldNames
	 */
	function addAkismetSetting($hookname, $args) {
		$fields =& $args[1];
		$fields = array_merge($fields, array($this->getName()."::".$this->dataUserSetting));
		return false;
	}

	/**
	 * Get the display name of this plugin.
	 * @return String
	 */
	function getDisplayName() {
		return __('plugins.generic.akismet.displayName');
	}

	/**
	 * Get a description of the plugin.
	 * @return String
	 */
	function getDescription() {
		return __('plugins.generic.akismet.description');
	}

	/**
	 * Set the page's breadcrumbs, given the plugin's tree of items
	 * to append.
	 * @param $isSubclass boolean
	 */
	function setBreadcrumbs($isSubclass = false) {
		$templateMgr =& TemplateManager::getManager();
		$pageCrumbs = array(
			array(
				Request::url(null, 'user'),
				'navigation.user'
			),
			array(
				Request::url(null, 'manager'),
				'user.role.manager'
			)
		);
		if ($isSubclass) {
			$pageCrumbs[] = array(
				Request::url(null, 'manager', 'plugins'),
				'manager.plugins'
			);
			$pageCrumbs[] = array(
				Request::url(null, 'manager', 'plugins', 'generic'),
				'plugins.categories.generic'
			);
		}

		$templateMgr->assign('pageHierarchy', $pageCrumbs);
	}


	/**
	 * Display verbs for the management interface.
	 * @return array of verb => description pairs
	 */
	function getManagementVerbs() {
		$verbs = array();
		if ($this->getEnabled()) {
			$verbs[] = array('settings', __('manager.plugins.settings'));
		}
		return parent::getManagementVerbs($verbs);
	}

	/**
	 * Execute a management verb on this plugin
	 * @param $verb string
	 * @param $args array
	 * @param $message string Result status message
	 * @param $messageParams array Parameters for the message key
	 * @return boolean
	 */
	function manage($verb, $args, &$message, &$messageParams) {
		if (!parent::manage($verb, $args, $message, $messageParams)) {
			// If enabling this plugin, go directly to the settings
			if ($verb == 'enable') {
				$verb = 'settings';
			} else {
				return false;
			}
		}

		$user =& Request::getUser();
		import('classes.notification.NotificationManager');
		$notificationManager = new NotificationManager();
		switch ($verb) {
			case 'settings':
				$templateMgr =& TemplateManager::getManager();
				$templateMgr->register_function('plugin_url', array(&$this, 'smartyPluginUrl'));
				$journal =& Request::getJournal();

				$this->import('AkismetSettingsForm');
				$form = new AkismetSettingsForm($this, $journal->getId());
				if (Request::getUserVar('save')) {
					$form->readInputData();
					if ($form->validate()) {
						$form->execute();
						$notificationManager->createTrivialNotification($user->getId());
						Request::redirect(null, 'manager', 'plugins', 'generic');
						return false;
					} else {
						$this->setBreadCrumbs(true);
						$form->display();
					}
				} else {
					$form->initData();
					$this->setBreadCrumbs(true);
					$form->display();
				}
				return true;
			case 'markSpamUser':
				$userid = $args[0];
				if ($this->reportMissedSpamUser($userid)) {
					$this->unsetAkismetData($userid);
					$notificationManager->createTrivialNotification($user->getId(), NOTIFICATION_TYPE_SUCCESS, array('contents' => __('plugins.generic.akismet.spamDetected')));
				} else {
					$notificationManager->createTrivialNotification($user->getId(), NOTIFICATION_TYPE_ERROR, array('contents' => __('plugins.generic.akismet.spamFailed')));
				}
				Request::redirect(null, 'manager', 'editUser', $userid);
			default:
				// Unknown management verb
				assert(false);
				return false;
		}
	}
	
	/**
	 * Hook callback: check a form submission for spam content
	 * @see Form::validate()
	 */
	function checkAkismet($hookName, $args) {
		// The Akismet API key is required
		$journal =& Request::getJournal();
		$apikey = $this->getSetting($journal->getId(), 'akismetKey');
		if (empty($apikey)) {
			return false;
		}
		// Supported hooks must be enumerated here to identify the content to be checked and the form being used
		$form = NULL;
		$data = array();
		switch ($hookName) {
			case 'registrationform::validate':
				$form = $args[0];
				$data = array(
					'comment_type' => 'signup',
					'comment_author' => implode(' ', array_filter(array($form->getData('firstName'), $form->getData('middleName'), $form->getData('lastName')))),
					'comment_author_email' => $form->getData('email'),
					'comment_author_url' => $form->getData('userUrl'),
					'comment_content' => implode(' ', array_values($form->getData('biography'))), //ticket 1163344 with Akismet suggests: "I'd recommend just making one comment-check call with all of the translated text at once."
				);
				$errorField = 'username';
				break;
			case 'commentform::validate':
				$form = $args[0];
				$data = array(
					'comment_type' => 'comment',
					'comment_author' => $form->getData('posterName'),
					'comment_author_email' => $form->getData('posterEmail'),
					'comment_content' => implode("\n", array($form->getData('title'), $form->getData('body'))),
				);
				$errorField = 'body';
				break;
			default:
				return false;
		}
		$locales = $journal->getSupportedFormLocaleNames();
		$iso639_1 = array();
		foreach (array_keys($locales) as $locale) {
			// Our locale names are of the form ISO639-1 + "_" + ISO3166-1 
			$iso639_1[] = array_pop(explode('_', $locale, 1));
		}
		$data = array_merge(
			$data,
			array(
				'blog' => $journal->getUrl(),
				'user_ip' => $_SERVER['REMOTE_ADDR'],
				'user_agent' => $_SERVER['HTTP_USER_AGENT'],
				'referrer' => $_SERVER['HTTP_REFERER'],
				'blog_lang' => implode(', ', array_unique($iso639_1)),
				'blog_charset' => '',
			)
		);
		// if the form is already invalid, do not check Akismet
		if (!$form->isValid()) {
			return false;
		}
		// send the request to Akismet
		if ($this->_sendPayload($data)) {
			$form->addError($errorField, __('plugins.generic.akismet.spamDetected'));
		} else if ($hookName === 'registrationform::validate') {
			// remember this successful check in the session
			// we'll store it as a user setting on form execution
			$sessionManager =& SessionManager::getManager();
			$session =& $sessionManager->getUserSession();
			$session->setSessionVar($this->getName()."::".$this->dataUserSetting, $data);
		}
		// returning false allows processing to continue
		return false;
	}

	/**
	 * Get the data submitted to Akismet for a user
	 * @param $userId int User ID
	 * @return array
	 */
	function getAkismetData($userId) {
		$userdao = DAORegistry::getDAO('UserDAO');
		$user = $userdao->getById($userId);
		if (isset($user)) {
			return $user->getData($this->getName()."::".$this->dataUserSetting);
		}
		return;
	}

	/**
	 * Get the data submitted to Akismet for a user
	 * @param $userId int User ID
	 */
	function unsetAkismetData($userId) {
		$userdao = DAORegistry::getDAO('UserDAO');
		$user = $userdao->getById($userId);
		if (isset($user)) {
			$user->setData($this->getName()."::".$this->dataUserSetting, '');
			$userdao->updateObject($user);
		}
	}

	/**
	 * Hook callback: store an Akismet submission which passed the spam check, in case we later want to report it as spam
	 * @see Form::execute()
	 */
	function storeAkismetData($hookName, $args) {
		// Supported hooks must be enumerated here to identify the object being used
		$user = NULL;
		$data = NULL;
		switch ($hookName) {
			case 'registrationform::execute':
				// The original data can be found in the user session, per checkAkismet()
				$user = $args[1];
				$sessionManager =& SessionManager::getManager();
				$session =& $sessionManager->getUserSession();
				$data = $session->getSessionVar($this->getName()."::".$this->dataUserSetting);
				break;
			case 'commentform::execute':
				// Currently UserSettingsDAO hardcodes associations by journal
				// If this were relaxed, we could store spam submissions by associated comment
			default:
				return false;
		}
		// if we have a user and data to store, modify the user
		if (isset($user) && isset($data)) {
			$user->setData($this->getName()."::".$this->dataUserSetting, $data);
			$session->unsetSessionVar($this->getName()."::".$this->dataUserSetting);
		}
		// returning false allows processing to continue
		return false;
	}

	/**
	 * Report a missed spam user
	 * @param $userid int
	 * @return bool
	 */
	function reportMissedSpamUser($userid) {
		$data = $this->getAkismetData($userid);
		if (isset($data)) {
			return $this->_sendPayload($data, true);
		}
		return false;
	}

	/**
	 * Hook callback: register output filter to add option to mark spam user
	 * @see TemplateManager::display()
	 */
	function handleTemplateDisplay($hookName, $args) {
		$templateMgr =& $args[0];
		$template =& $args[1];

		switch ($template) {
			case 'manager/people/userProfileForm.tpl':
				$userid = $templateMgr->get_template_vars('userId');
				if ($userid) {
					$data = $this->getAkismetData($userid);
					if (isset($data) && !empty($data)) {
						$templateMgr->register_outputfilter(array($this, 'markSpamFilter'));
					}
				}
				break;
		}
		return false;
	}

	/**
	 * Output filter to create a "mark spam" button on a user's profile
	 * @param $output string
	 * @param $templateMgr TemplateManager
	 * @return $string
	 */
	function markSpamFilter($output, &$templateMgr) {
		if (preg_match('/<form id="userForm"[^>]+>/', $output, $matches, PREG_OFFSET_CAPTURE)) {
			$templateMgr->assign('akismetPlugin', $this->getName());
			$offset = $matches[0][1];
			$newOutput = substr($output, 0, $offset);
			$newOutput .= $templateMgr->fetch($this->getTemplatePath() . 'submitSpam.tpl');;
			$newOutput .= substr($output, $offset);
			$output = $newOutput;
		}
		$templateMgr->unregister_outputfilter('markSpamFilter');
		return $output;
	}

	/**
	 * Send a payload to Akismet
	 * @param $data array Array keyed by Akismet API parameters
	 * @param $flag bool True to report the data as spam, false to check the data
	 * @return bool Is the content spam?
	 */
	function _sendPayload($data, $flag = false) {
		// Confirm the minimum required fields for Akismet are present
		foreach ($this->requiredFields as $f) {
			if (empty($data[$f])) {
				return false;
			}
		}
		// build the Akismet HTTP request
		$requestBody = '';
		foreach ($data as $k => $v) {
			if (!empty($v)) {
				$requestBody .= '&'.$k.'='.urlencode($v);
			}
		}
		$requestBody = ltrim($requestBody, '&');
		$journal =& Request::getJournal();
		$host = $this->getSetting($journal->getId(), 'akismetKey').'.rest.akismet.com';
		$port = 443;
		$path = '/1.1/' . ($flag ? 'submit-spam' : 'comment-check');
		$versionDao =& DAORegistry::getDAO('VersionDAO');
		$dbVersion =& $versionDao->getCurrentVersion();
		$ua = $dbVersion->getProduct().' '.$dbVersion->getVersionString().' | Akismet/3.1.7';
		$httpRequest = "POST {$path} HTTP/1.0\r\n";
		$httpRequest .= "Host: {$host}\r\n";
		$httpRequest .= "Content-Type: application/x-www-form-urlencoded\r\n";
		$httpRequest .= "Content-Length: ".strlen($requestBody)."\r\n";
		$httpRequest .= "User-Agent: {$ua}\r\n";
		$httpRequest .= "\r\n{$requestBody}";
		$response = $errno = $errstr = $headers = $content = '';
		if (false != ($socket = fsockopen('ssl://'.$host, $port, $errno, $errstr))) {
			fwrite($socket, $httpRequest);
			while (!feof($socket)) {
				$response .= fgets($socket);
			}
			fclose($socket);
			list($headers, $content) = explode("\r\n\r\n", $response, 2);
		}
		return ((!$flag && $content === 'true') || ($flag && $content === 'Thanks for making the web a better place.'));
	}

}
?>
