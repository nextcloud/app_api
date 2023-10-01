<?php

$appId = OCA\AppAPI\AppInfo\Application::APP_ID;
\OCP\Util::addScript($appId, $appId . '-main');
\OCP\Util::addStyle('app_api', 'settings');

?>

<div id="content"></div>
