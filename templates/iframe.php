<?php

$appId = OCA\AppAPI\AppInfo\Application::APP_ID;
\OCP\Util::addScript($appId, $appId . '-iframe');

?>

<div id="content"></div>
