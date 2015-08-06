<?php

require_once __DIR__.'/../init.php';

$configPath = getenv('Ric_config') ? getenv('Ric_config') : __DIR__.'/../config/config.json';
$ricServer = new Ric_Server_Server($configPath);
$ricServer->handleRequest();