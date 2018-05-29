{**
 * plugins/generic/akismet/settingsForm.tpl
 *
 * Copyright (c) 2018 University of Pittsburgh
 * Distributed under the GNU GPL v2 or later. For full terms see the LICENSE file.
 *
 * Akismet plugin settings
 *
 *}
{strip}
{assign var="pageTitle" value="plugins.generic.akismet.manager.akismetSettings"}
{include file="common/header.tpl"}
{/strip}
<div id="akismetSettings">
<div id="description">{translate key="plugins.generic.akismet.manager.settings.description"}</div>

<div class="separator"></div>

<br />

{if $akismetKey}
<p class="akismetKeySet">
{translate key="plugins.generic.akismet.manager.settings.akismetKeySet"}
<p>
{/if}

<form method="post" action="{plugin_url path="settings"}">
{include file="common/formErrors.tpl"}
<table width="100%" class="data">
	<tr valign="top" id="akismetPath">
		<td width="20%" class="label">{fieldLabel name="akismetKey" key="plugins.generic.akismet.manager.settings.akismetKey" required="true"}</td>
		<td width="80%" class="value"><input type="text" name="akismetKey" id="akismetKey" value="" size="25" class="textField" /></td>
	</tr>
</table>

<br/>

<input type="submit" name="save" class="button defaultButton" value="{translate key="common.save"}"/>
<input type="button" class="button" value="{translate key="common.cancel"}" onclick="history.go(-1)"/>
</form>

<p><span class="formRequired">{translate key="common.requiredField"}</span></p>
</div>
{include file="common/footer.tpl"}
