<?php

include __DIR__.'/../../src/Ric/Server/Server.php';

$configPath = __DIR__.'/../../config/config.json';
$configManager = new Ric_Server_ConfigManager($configPath);
$api = new Ric_Server_Api(
    new Ric_Server_Server($configManager),
    new Ric_Server_Auth_Manager($configManager->getConfig()));
$api->handleRequest();
