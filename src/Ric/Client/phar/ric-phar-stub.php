#!/usr/bin/php -d variables_order=EGPCS
<?php

Phar::mapPhar("ric.phar");
require_once 'phar://ric.phar/Cli.php';
require_once 'phar://ric.phar/CliHandler.php';
require_once 'phar://ric.phar/Client.php';
require_once 'phar://ric.phar/RestClient.php';

exit(Ric_Client_CliHandler::handleExecute($argv, $_ENV)); // command line status


__HALT_COMPILER();