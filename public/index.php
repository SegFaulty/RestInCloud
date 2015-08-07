<?php

require_once __DIR__.'/../init.php';

$configPath = getenv('Ric_config') ? getenv('Ric_config') : __DIR__.'/../config/config.json';
$config = (new Ric_Server_Config())->loadConfig($configPath);
$api = new Ric_Server_Api(new Ric_Server_Server($config), new Ric_Server_Auth_Service($config));
$api->handleRequest();