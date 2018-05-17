<?php

/**
 * @defgroup plugins_generic_akismet
 */
 
/**
 * @file plugins/generic/akismet/index.php
 *
 * Copyright (c) 2018 University of Pittsburgh
 * Distributed under the GNU GPL v2 or later. For full terms see the LICENSE file.
 *
 * @ingroup plugins_generic_akismet
 * @brief Wrapper for Akismet plugin.
 *
 */

require_once('AkismetPlugin.inc.php');

return new AkismetPlugin();

?>
