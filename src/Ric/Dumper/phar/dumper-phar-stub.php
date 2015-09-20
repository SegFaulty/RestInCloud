#!/usr/bin/php -d variables_order=EGPCS
<?php

# make sure errors go to stderr
ini_set('log_errors', 1);
ini_set('error_log', 'syslog');
ini_set('display_errors', 'stderr');

Phar::mapPhar('dumper.phar');
require_once 'phar://dumper.phar/Cli.php';
require_once 'phar://dumper.phar/SyntacticSugar.php';

exit(Ric_Client_CliHandler::handleExecute($argv, $_ENV, file_get_contents('phar://dumper.phar/README.md'))); // command line status

__HALT_COMPILER();