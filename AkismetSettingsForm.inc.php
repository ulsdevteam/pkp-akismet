<?php

/**
 * @file plugins/generic/akismet/AkismetSettingsForm.inc.php
 *
 * Copyright (c) University of Pittsburgh
 * Distributed under the GNU GPL v2 or later. For full terms see the LICENSE file.
 *
 * @class AkismetSettingsForm
 * @ingroup plugins_generic_akismet
 *
 * @brief Form for site admins to modify Akismet plugin settings
 */


import('lib.pkp.classes.form.Form');

class AkismetSettingsForm extends Form {

	/** @var $plugin object */
	var $_plugin;

	/**
	 * Constructor
	 * @param $plugin object
	 */
	function __construct($plugin) {
		$this->_plugin = $plugin;
		
		parent::__construct(method_exists($plugin, 'getTemplateResource') ? $plugin->getTemplateResource('settingsForm.tpl') : $plugin->getTemplatePath() . 'settingsForm.tpl');

		$this->addCheck(new FormValidator($this, 'akismetKey', FORM_VALIDATOR_REQUIRED_VALUE, 'plugins.generic.akismet.manager.settings.akismetKeyRequired'));
		$this->addCheck(new FormValidatorPost($this));
	}

	/**
	 * Initialize form data.
	 */
	function initData() {
		$plugin = $this->_plugin;

		$this->setData('akismetKey', $plugin->getSetting(CONTEXT_SITE, 'akismetKey'));
		$this->setData('akismetPrivacyNotice', $plugin->getSetting(CONTEXT_SITE, 'akismetPrivacyNotice'));
	}

	/**
	 * Assign form data to user-submitted data.
	 */
	function readInputData() {
		$this->readUserVars(array('akismetKey', 'akismetPrivacyNotice'));
	}

	/**
	 * Save settings.
	 */
	function execute(...$functionArgs) {
		$plugin = $this->_plugin;
		$plugin->updateSetting(CONTEXT_SITE, 'akismetKey', $this->getData('akismetKey'), 'string');
		$plugin->updateSetting(CONTEXT_SITE, 'akismetPrivacyNotice', $this->getData('akismetPrivacyNotice'), 'boolean');
	}

	/**
	 * Fetch the form.
	 * @copydoc Form::fetch()
	 */
	function fetch($request, $template = NULL, $display = false) {
		$templateMgr = TemplateManager::getManager($request);
		$templateMgr->assign('pluginName', $this->_plugin->getName());
		return parent::fetch($request);
	}
}
