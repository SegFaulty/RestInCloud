<?php

require_once __DIR__.'/../init.php';

date_default_timezone_set(@date_default_timezone_get()); // Suppress DateTime warnings
// php server
if( php_sapi_name()=='cli-server' ){

    // for development:
//    ini_set('display_errors', true);
//    ini_set('log_errors', true);
//    ini_set("error_log", '/home/www/ricServerErrors.log');

    $ricServer = new Ric_Server_Server(H::getIKS($_ENV, 'Ric_config', './config.json'));
    $ricServer->handleRequest();
    return true; // if false, the internal server will serve the REQUEST_URI .. this is dangerous

}

// cli commands
if( php_sapi_name()=='cli' ){

    switch(H::getIKS($argv,1)){
        case 'purge':
            Ric_Server_Server::cliPurge($argv);
            break;
        default:
            die(
                'please start it as webserver:'."\n"
                .' Ric_config=./config.json php -d variables_order=GPCSE -S 0.0.0.0:3070 '.__FILE__."\n"
                .'   OR   '."\n"
                .'php '.__FILE__.' purge /path/to/storeDir {maxTimestamp}'."\n"
                .'  to purge all files marked for deletion (with fileMtime < maxTimestamp)'."\n"
            );
    }
    return 1;
}

// use in http or what ever sapi
# $ricServer = new Ric_Server_Server('path_to/config.json'));
# $ricServer->handleRequest();