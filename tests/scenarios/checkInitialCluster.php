<?php

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../../init.php';


ln('start checkInitialCluster');

$cli = new Ric_Client_Cli($argv,[]);

$servers = $cli->arguments;
if( count($servers)!=4 ){
	ln('i need 4 servers to play, please add them as arguments ... '.__FILE__.' server1:3377 server1:3378 server1:3379 server1:3380');
	exit;
}



ln('check servers');
foreach( $servers as $server ){
	ln('check '.$server);
	ln('check server config');
	$result = unJson(Ric_Rest_Client::get($server.'?info',['token'=>'admin']));
#	ln(print_r($result, true));
	check(isset($result['config']['servers']), 'Server has no server config?!!?!');
	check(count($result['config']['servers'])===0, 'Server is already joined in a cluster: '.join(',', $result['config']['servers']).' send a leaveCluster like:  curl -L -X POST "46.101.154.98:3778" --data "action=leaveCluster&joinCluster=46.101.154.98:3777&token=admin"');


	ln('check files');
	$result = unJson(Ric_Rest_Client::get($server.'?list',['token'=>'admin']));
	check(count($result)==0, 'Server has already files! ('.join(',', $result).') thats not good, i need a empty one to play!');

}
ln('server checks passed');


ln('build cluster');
// join server 1 to 0
$result = unJson(Ric_Rest_Client::post($servers[1].'?',['action'=>'joinCluster','joinCluster'=>$servers[0],'token'=>'admin']));
check($result['Status']=='OK', 'joinServer failed for server '.$servers[1]);
// check config server 1
$result = unJson(Ric_Rest_Client::get($servers[1].'?info',['token'=>'admin']));
check(count($result['config']['servers'])===1, 'joinCluster failed for server[1] expected server[0] but is: '.join(',', $result['config']['servers']));
// check config server 0
$result = unJson(Ric_Rest_Client::get($servers[0].'?info',['token'=>'admin']));
check(count($result['config']['servers'])===1, 'joinCluster failed servers of server[0]: '.join(',', $result['config']['servers']));

// join server 2 to 0
$result = unJson(Ric_Rest_Client::post($servers[2].'?',['action'=>'joinCluster','joinCluster'=>$servers[0],'token'=>'admin']));
check($result['Status']=='OK', 'joinServer failed for server '.$servers[1]);
// check config server 2
$result = unJson(Ric_Rest_Client::get($servers[2].'?info',['token'=>'admin']));
check(count($result['config']['servers'])===2, 'joinCluster failed for server[2] expected server[0,1] but is: '.join(',', $result['config']['servers']));
// check config server 0
$result = unJson(Ric_Rest_Client::get($servers[0].'?info',['token'=>'admin']));
check(count($result['config']['servers'])===2, 'joinCluster failed servers of server[0]: '.join(',', $result['config']['servers']));

ln('cluster is up and running');


ln('tear down cluster');
$result = unJson(Ric_Rest_Client::post($servers[0].'?',['action'=>'leaveCluster','token'=>'admin']));
check($result['Status']=='OK', 'leaveCluster failed for server '.$servers[0]);
$result = unJson(Ric_Rest_Client::post($servers[1].'?',['action'=>'leaveCluster','token'=>'admin']));
check($result['Status']=='OK', 'leaveCluster failed for server '.$servers[1]);
// check third server, must be standalone
$result = unJson(Ric_Rest_Client::get($servers[2].'?info',['token'=>'admin']));
check(count($result['config']['servers'])===0, 'Server is already joined in a cluster something failed: '.join(',', $result['config']['servers']));
ln('all servers leaved the cluster');


#$result = unJson(Ric_Rest_Client::post($servers[2].'?',['action'=>'joinCluster','joinCluster'=>$servers[0],'token'=>'admin']));
#check($result['Status']=='OK', 'joinServer failed for server '.$servers[2]);
#$result = unJson(Ric_Rest_Client::post($servers[3].'?',['action'=>'joinCluster','joinCluster'=>$servers[0],'token'=>'admin']));
#check($result['Status']=='OK', 'joinServer failed for server '.$servers[3]);


