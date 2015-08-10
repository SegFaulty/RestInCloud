<?php

require_once __DIR__.'/../../../init.php';

$configPath = __DIR__.'/../../../config/config.json';
$configManager = new Ric_Server_ConfigManager($configPath);
$api = new Ric_Server_Api(
    new Ric_Server_Server($configManager),
    new Ric_Server_Auth_Manager($configManager->getConfig()));
$api->handleRequest();
