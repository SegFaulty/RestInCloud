<?php

include __DIR__.'/../src/Ric/Server/Server.php';

$ricServer = new Ric_Server_Server(__DIR__.'/config.json');
$ricServer->handleRequest();
