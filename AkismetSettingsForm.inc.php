<?php

/**
 * @file plugins/generic/akismet/AkismetSettingsForm.inc.php
 *
 * Copyright (c) 2018 University of Pittsburgh
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
	var $plugin;

	/**
	 * Constructor
	 * @param $plugin object
	 */
	function AkismetSettingsForm(&$plugin) {
		$this->plugin =& $plugin;
		
		parent::Form($plugin->getTemplatePath() . 'settingsForm.tpl');

		$this->addCheck(new FormValidator($this, 'akismetKey', 'required', 'plugins.generic.akismet.manager.settings.akismetKeyRequired'));
		$this->addCheck(new FormValidatorPost($this));
	}

	/**
	 * Initialize form data.
	 */
	function initData() {
		$plugin =& $this->plugin;

		$this->_data = array();
		$this->_data['akismetKey'] = $plugin->getSetting(CONTEXT_SITE, 'akismetKey');
	}

	/**
	 * Assign form data to user-submitted data.
	 */
	function readInputData() {
		$this->readUserVars(array('akismetKey'));
	}

	/**
	 * Save settings.
	 */
	function execute() {
		$plugin =& $this->plugin;
		$plugin->updateSetting(CONTEXT_SITE, 'akismetKey', $this->getData('akismetKey'), 'string');
	}

}

?>
