<?php

require(dirname(__FILE__) . '/ai-server.php');

$myConfig = array();

$server = new AI_Server($myConfig);
$server->go();
