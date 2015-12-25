<?php

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../../init.php';


$cli = new Ric_Client_Cli($argv,[]);

$servers = $cli->getArguments();
if( count($servers)!=4 ){
	ln('i need 4 servers to play, please add them as arguments ... '.__FILE__.' server1:3377 server1:3378 server1:3379 server1:3380');
	exit;
}
