#!/usr/bin/php -d variables_order=EGPCS
<?php

# make sure errors go to stderr
ini_set('log_errors', 1);
ini_set('error_log', 'syslog');
ini_set('display_errors', 'stderr' );

Phar::mapPhar("ric.phar");
require_once 'phar://ric.phar/Cli.php';
require_once 'phar://ric.phar/CliHandler.php';
require_once 'phar://ric.phar/Client.php';
require_once 'phar://ric.phar/RestClient.php';
require_once 'phar://ric.phar/SyntacticSugar.php';

exit(Ric_Client_CliHandler::handleExecute($argv, $_ENV)); // command line status

__HALT_COMPILER();