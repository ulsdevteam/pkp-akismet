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
	 * @var requiredFields array Required Akisment API fields
	 */
	var $requiredFields = array('blog', 'user_ip', 'user_agent');

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
		}
		return $success;
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
						$user =& Request::getUser();
						import('classes.notification.NotificationManager');
						$notificationManager = new NotificationManager();
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
		if (empty($this->getSetting($journal->getId(), 'akismetKey'))) {
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
				'is_test' => 'true',
			)
		);
		// if the form is already invalid, do not check Akismet
		if (!$form->isValid()) {
			return false;
		}
		// send the request to Akismet
		if ($this->_checkPayload($data)) {
			$form->addError($errorField, __('plugins.generic.akismet.spamDetected'));
		}
		// returning false allows processing to continue
		return false;
	}

	/**
	 * Check a payload against Akismet
	 * @param $data array Array keyed by Akismet API parameters
	 */
	function _checkPayload($data) {
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
		$path = '/1.1/comment-check';
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
		return ($content === 'true');
	}

}
?>
