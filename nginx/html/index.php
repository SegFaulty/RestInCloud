<?php

include __DIR__.'/../../src/Ric/Server/Server.php';

$config = (new Ric_Server_Config())->loadConfig($configPath);
$api = new Ric_Server_Api(new Ric_Server_Server($config), new Ric_Server_Auth_Service($config));
$api->handleRequest();
