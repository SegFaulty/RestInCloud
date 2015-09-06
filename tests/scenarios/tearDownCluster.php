<?php

require_once __DIR__.'/init.php';

ln('tear down cluster');
// server 4
$response = Ric_Rest_Client::post($servers[3].'?',['action'=>'leaveCluster','token'=>'admin']);
checkOk($response, 'leaveCluster failed for server '.$servers[0]);

$response = Ric_Rest_Client::post($servers[0].'?',['action'=>'leaveCluster','token'=>'admin']);
checkOk($response, 'leaveCluster failed for server '.$servers[0]);
$response = Ric_Rest_Client::post($servers[1].'?',['action'=>'leaveCluster','token'=>'admin']);
checkOk($response, 'leaveCluster failed for server '.$servers[1]);
// check third server, must be standalone
$result = unJson(Ric_Rest_Client::get($servers[2].'?info',['token'=>'admin']));
check(count($result['config']['servers'])===0, 'Server is already joined in a cluster something failed: '.join(',', $result['config']['servers']));
ln('all servers leaved the cluster');

ln('delete testFiles');
foreach( $servers as $server ){
	$result = unJson(Ric_Rest_Client::delete($server.'/testfile.txt?token=admin', __FILE__));
	checkOK($result, 'delete failed response:'.print_r($result, true));
	$result = unJson(Ric_Rest_Client::delete($server.'/testfile2.txt?token=admin', __FILE__));
	checkOK($result, 'delete failed response:'.print_r($result, true));
	$result = unJson(Ric_Rest_Client::delete($server.'/testFile.txt?token=admin', __FILE__));
	checkOK($result, 'delete failed response:'.print_r($result, true));
	$result = unJson(Ric_Rest_Client::delete($server.'/testFile2.txt?token=admin', __FILE__));
	checkOK($result, 'delete failed response:'.print_r($result, true));

}
