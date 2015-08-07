<?php

include __DIR__.'/../../src/Ric/Server/Server.php';

$config = (new Ric_Server_Config())->loadConfig($configPath);
$ricServer = new Ric_Server_Server($config);
$ricServer->handleRequest();
