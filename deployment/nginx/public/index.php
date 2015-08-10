<?php

require_once __DIR__.'/../../../init.php';

//var_dump($_SERVER);exit;

$configPath = __DIR__.'/../../../config/config.json';
$configService = new Ric_Server_Config($configPath);
$api = new Ric_Server_Api(
    new Ric_Server_Server($configService),
    new Ric_Server_Auth_Service($configService->getConfig()));
$api->handleRequest();
