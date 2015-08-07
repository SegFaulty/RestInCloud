<?php

require_once __DIR__.'/../../../init.php';

$configPath = getenv('Ric_config') ? getenv('Ric_config') : realpath(__DIR__.'/../../../config/config.json');
$config = (new Ric_Server_Config())->loadConfig($configPath);
$ricServer = new Ric_Server_Server($config);
$ricServer->handleRequest();
