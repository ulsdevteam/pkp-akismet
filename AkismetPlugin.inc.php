<?php

/**
 * @file plugins/generic/akismet/AkismetPlugin.inc.php
 *
 * Copyright (c) University of Pittsburgh
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
	 * @copydoc LazyLoadPlugin::register()
	 */
	function register($category, $path, $mainContextId = NULL) {
		$success = parent::register($category, $path, $mainContextId);
		if (!Config::getVar('general', 'installed') || defined('RUNNING_UPGRADE')) return true;
		if ($success && $this->getEnabled()) {

			// Enable Akismet anti-spam check of new registrations
			HookRegistry::register('registrationform::validate', array(&$this, 'checkAkismet'));
			HookRegistry::register('registrationform::execute', array(&$this, 'storeAkismetData'));
			HookRegistry::register('userdao::getAdditionalFieldNames', array(&$this, 'addAkismetSetting'));
			// Add a link to mark a missed spam user
			HookRegistry::register('TemplateManager::fetch', array($this, 'templateFetchCallback'));
			// Add a handler to process a missed spam user
			HookRegistry::register('LoadComponentHandler', array($this, 'callbackLoadHandler'));
			// Register callback to add text to registration page
			HookRegistry::register('TemplateManager::display', array($this, 'handleTemplateDisplay'));
			}
		return $success;
	}

	/**
	 * Override the getEnabled() method to support CLI use
	 * @see LazyLoadPlugin::getEnabled
	 */
	function getEnabled($contextId = NULL) {
		// Check the parent's functionality for normal enabling at the journal level
		if (parent::getEnabled($contextId)) {
			return true;
		}
		// If we are running in a CLI, the plugin is always enabled at the site level
		if (!isset($_SERVER['SERVER_NAME'])) {
			return true;
		}
		return false;
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
	 * @copydoc Plugin::getActions()
	 */
	function getActions($request, $verb) {
		$router = $request->getRouter();
		import('lib.pkp.classes.linkAction.request.AjaxModal');
		// Must be site administrator to access the settings option
		return array_merge(
				$this->getEnabled() && Validation::isSiteAdmin() ? array(
			new LinkAction(
					'settings', new AjaxModal(
					$router->url($request, null, null, 'manage', null, array('verb' => 'settings', 'plugin' => $this->getName(), 'category' => 'generic')), $this->getDisplayName()
					), __('manager.plugins.settings'), null
			),
				) : array(), parent::getActions($request, $verb)
		);
	}

	/**
	 * @copydoc Plugin::manage()
	 */
	function manage($args, $request) {
		$user =& Request::getUser();
		import('classes.notification.NotificationManager');
		$notificationManager = new NotificationManager();
		switch ($request->getUserVar('verb')) {
			case 'settings':
				if (!Validation::isSiteAdmin()) {
					return new JSONMessage(false);
				}
				$templateMgr =& TemplateManager::getManager();
				$templateMgr->register_function('plugin_url', array(&$this, 'smartyPluginUrl'));

				$this->import('AkismetSettingsForm');
				$form = new AkismetSettingsForm($this);
				if ($request->getUserVar('save')) {
					$form->readInputData();
					if ($form->validate()) {
						$form->execute();
						return new JSONMessage(true);
					}
				} else {
						$form->initData();
				}
				return new JSONMessage(true, $form->fetch($request));
		}
		return parent::manage($args, $request);
	}
	
	/**
	 * Hook callback: check a form submission for spam content
	 * @see Form::validate()
	 */
	function checkAkismet($hookName, $args) {
		$request = Application::getRequest();
		$context = $request->getContext();
		// The Akismet API key is required
		$apikey = $this->getSetting(CONTEXT_SITE, 'akismetKey');
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
				);
				$errorField = 'username';
				break;
			default:
				return false;
		}
		$locales = $context->getSupportedFormLocaleNames();
		$iso639_1 = array();
		foreach (array_keys($locales) as $locale) {
			// Our locale names are of the form ISO639-1 + "_" + ISO3166-1 
			$iso639_1[] = array_pop(explode('_', $locale, 1));
		}
		$data = array_merge(
			$data,
			array(
				'blog' => $request->getBaseUrl(),
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
				$sessionManager =& SessionManager::getManager();
				$session =& $sessionManager->getUserSession();
				$data = $session->getSessionVar($this->getName()."::".$this->dataUserSetting);
				// Prior to 3.1.2 the user was passed as an argument
				$user = $args[1];
				// For 3.1.2 and 3.1.2-1, we need a hack:
				if (!$user) {
					$form = $args[0];
					$username = $form->getData('username');
					$userDao = DAORegistry::getDAO('UserDAO');
					$settingName = $this->getName()."::".$this->dataUserSetting;
					$session->unsetSessionVar($this->getName()."::".settingName);
					// On shutdown, persist the Akismet setting to the new user account. (https://github.com/pkp/pkp-lib/issues/4601)
					register_shutdown_function(function() use ($username, $userDao, $settingName, $data) {
						$user = $userDao->getByUsername($username);
						$user->setData($settingName, $data);
						$userDao->updateObject($user);
					});
				}
				break;
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
	 * Adds an additional link to user grid row
	 * @param $hookName string The name of the invoked hook
	 * @param $params array Hook parameters
	 */
	public function templateFetchCallback($hookName, $params) {
		$request = $this->getRequest();
		$router = $request->getRouter();

		$resourceName = $params[1];
		if ($resourceName === 'controllers/grid/gridRow.tpl') {
			$templateMgr = $params[0];
			// fetch the gridrow from the template
			if (method_exists($templateMgr, 'getTemplateVars')) {
				// Smarty 3
				$row = $templateMgr->getTemplateVars('row');
			} else {
				// Smarty 2
				$row = $templateMgr->get_template_vars('row');
			}
			$data = $row ? $row->getData() : array();
			// Is this a User grid?
			if (is_a($data, 'User')) {
				// userid from the grid
				$userid = $data->getId();
				$akismetData = $this->getAkismetData($userid);
				// current user
				$user = $request->getUser();
				// Is data present, and is the user able to administer this row?
				if ($row->hasActions() && isset($akismetData) && !empty($akismetData) && Validation::canAdminister($userid, $user->getId())) {
					import('lib.pkp.classes.linkAction.request.RemoteActionConfirmationModal');
					$row->addAction(new LinkAction(
						'flagAsSpam',
						new RemoteActionConfirmationModal(
							$request->getSession(),
							__('plugins.generic.akismet.actions.confirmFlagAsSpam'),
							__('plugins.generic.akismet.actions.flagAsSpam'),
							$router->url($request, null, null, 'markAsSpam', null, array('userid' => $userid))
							),
						__('plugins.generic.akismet.actions.flagAsSpam'),
						null,
						__('plugins.generic.akismet.grid.action.flagAsSpam')
					));
				}
			}
		}
	}

	/**
	 * Hook callback: register output filter to add privacy notice
	 * @see TemplateManager::display()
	 */
	function handleTemplateDisplay($hookName, $args) {
		$templateMgr = $args[0];
		$template = $args[1];
		if ($template === 'frontend/pages/userRegister.tpl' && $this->getSetting(CONTEXT_SITE, 'akismetPrivacyNotice')) {
			if (method_exists($templateMgr, 'register_outputfilter')) {
				// 3.1.1 and earlier (Smarty 2)
				$templateMgr->register_outputfilter(array($this, 'registrationFilter'));
			} else {
				// 3.1.2 and later (Smarty 3)
				$templateMgr->registerFilter('output', array($this, 'registrationFilter'));
			}
		}
		return false;
	}

	/**
	 * Output filter adds privacy notice to registration form.
	 * @param $output string
	 * @param $templateMgr TemplateManager
	 * @return $string
	 */
	function registrationFilter($output, $templateMgr) {
		if (preg_match('/<form[^>]+id="register"[^>]+>/', $output, $matches, PREG_OFFSET_CAPTURE)) {
			$match = $matches[0][0];
			$offset = $matches[0][1];
			$newOutput = substr($output, 0, $offset+strlen($match));
			$newOutput .= '<div id="akismetprivacy">'.__('plugins.generic.akismet.privacyNotice').'</div>';
			$newOutput .= substr($output, $offset+strlen($match));
			$output = $newOutput;
			if (method_exists($templateMgr, 'unregister_outputfilter')) {
				// 3.1.1 and earlier (Smarty 2)
				$templateMgr->unregister_outputfilter('registrationFilter');
			} else {
				// 3.1.2 and later (Smarty 3)
				$templateMgr->unregisterFilter('output', array($this, 'registrationFilter'));
			}
		}
		return $output;
	}

	/**
	 * @see PKPComponentRouter::route()
	 */
	public function callbackLoadHandler($hookName, $args) {
		if ($args[0] === "grid.settings.user.UserGridHandler" && $args[1] === "markAsSpam") {
			$args[0] = "plugins.generic.akismet.AkismetHandler";
			import($args[0]);
			return true;
		}
		return false;
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
		$akismetKey = $this->getSetting(CONTEXT_SITE, 'akismetKey');
		if (empty($akismetKey)) {
			return false;
		}
		// build the Akismet HTTP request
		$requestBody = '';
		foreach ($data as $k => $v) {
			if (!empty($v)) {
				$requestBody .= '&'.$k.'='.urlencode($v);
			}
		}
		$requestBody = ltrim($requestBody, '&');
		$host = $akismetKey.'.rest.akismet.com';
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

	/**
	 * @see PKPPlugin::getTemplatePath()
	 */
	function getTemplatePath($inCore = false) {
		$templatePath = parent::getTemplatePath($inCore);
		// OJS 3.1.2 and later include the 'templates' directory, but no trailing slash
		$templateDir = 'templates';
		if (strlen($templatePath) >= strlen($templateDir)) {
			if (substr_compare($templatePath, $templateDir, strlen($templatePath) - strlen($templateDir), strlen($templateDir)) === 0) {
				return $templatePath;
			}
		}
		// OJS 3.1.1 and earlier includes a trailing slash to the plugin path
		return $templatePath . $templateDir . DIRECTORY_SEPARATOR;
	}	

}
