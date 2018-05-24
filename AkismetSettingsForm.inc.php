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
 * @brief Form for journal managers to modify Akismet plugin settings
 */


import('lib.pkp.classes.form.Form');

class AkismetSettingsForm extends Form {

	/** @var $journalId int */
	var $journalId;

	/** @var $plugin object */
	var $plugin;

	/**
	 * Constructor
	 * @param $plugin object
	 * @param $journalId int
	 */
	function AkismetSettingsForm(&$plugin, $journalId) {
		$this->plugin =& $plugin;
		$this->journalId = $journalId;
		
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
		$this->_data['akismetKey'] = $plugin->getSetting($this->journalId, 'akismetKey');
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
		$plugin->updateSetting($this->journalId, 'akismetKey', $this->getData('akismetKey'), 'string');
	}

}

?>
