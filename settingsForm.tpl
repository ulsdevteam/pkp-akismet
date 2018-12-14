{**
 * plugins/generic/akismet/settingsForm.tpl
 *
 * Copyright (c) University of Pittsburgh
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * Akismet plugin settings
 *
 *}
<script>
	$(function() {ldelim}
		// Attach the form handler.
		$('#akismetSettingsForm').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
	{rdelim});
</script>

<form class="pkp_form" id="akismetSettingsForm" method="post" action="{url router=$smarty.const.ROUTE_COMPONENT op="manage" category="generic" plugin=$pluginName verb="settings" save=true}">
	{csrf}
	{include file="controllers/notification/inPlaceNotification.tpl" notificationId="akismetSettingsFormNotification"}

	<div id="description">{translate key="plugins.generic.akismet.manager.settings.description"}</div>

	{fbvFormArea id="akismetSettingsFormArea"}
		{fbvElement type="text" name="akismetKey" value=$akismetKey label="plugins.generic.akismet.manager.settings.akismetKey"}
		{fbvFormSection list="true" id="akismetCheckboxList"}
			{fbvElement type="checkbox" name="akismetPrivacyNotice" checked=$akismetPrivacyNotice|compare:true label="plugins.generic.akismet.manager.settings.akismetPrivacyNotice"}
		{/fbvFormSection}
	{/fbvFormArea}

	{fbvFormButtons}

	<p><span class="formRequired">{translate key="common.requiredField"}</span></p>
</form>
