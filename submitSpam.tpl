{**
 * plugins/generic/akismet/submitSpam.tpl
 *
 * Copyright (c) 2018 University of Pittsburgh
 * Distributed under the GNU GPL v2 or later. For full terms see the LICENSE file.
 *
 * Submit user as spam to Akismet
 *
 *}
<form method="post" action="{url page="manager" op="plugin" path="generic"|to_array:$akismetPlugin:"markSpamUser":$userId}">
<input type="submit" name="save" class="button defaultButton" value="{translate key="plugins.generic.akismet.manager.flagAsSpam"}"/>
</form>