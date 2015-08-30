<?php

require_once __DIR__.'/init.php';

ln('start checkInitialCluster');

ln('check servers');
foreach( $servers as $server ){
	ln('check '.$server);
	ln('check server config');
	$result = unJson(ric('admin info', $server, 'admin'));
#	ln(print_r($result, true));
	check(isset($result['config']['servers']), 'Server has no server config?!!?!');
	check(count($result['config']['servers'])===0, 'Server is already joined in a cluster: '.join(',', $result['config']['servers']).' send a leaveCluster like:  curl -L -X POST "'.$server.'" --data "action=leaveCluster&token=admin"');
	ln('check files');
	$result = ric('admin list', $server, 'admin');
	check($result===PHP_EOL, 'Server has already files! '.$result.') thats not good, i need a empty one to play!
	  use something like curl -L -X DELETE "'.$server.'/'.reset(explode(PHP_EOL, $result)).'?token=writer"');

}
ln('server checks passed');

ln('build cluster');
// join server 1 to 0
$response = ric('admin joinCluster '.$servers[0], $servers[1], 'admin');
checkOk($response, 'joinServer failed for server '.$servers[1]);
// check config server 1
$result = unJson(ric('admin info', $servers[1], 'admin'));
check(count($result['config']['servers'])===1, 'joinCluster failed for server[1] expected server[0] but is: '.join(',', $result['config']['servers']));
// check config server 0
$result = unJson(ric('admin info', $servers[0], 'admin'));
check(count($result['config']['servers'])===1, 'joinCluster failed servers of server[0]: '.join(',', $result['config']['servers']));

// join server 2 to 0
$response = ric('admin joinCluster '.$servers[0], $servers[2], 'admin');;
checkOk($response, 'joinServer failed for server '.$servers[1]);
// check config server 2
$result = unJson(ric('admin info', $servers[2], 'admin'));
check(count($result['config']['servers'])===2, 'joinCluster failed for server[2] expected server[0,1] but is: '.join(',', $result['config']['servers']));
// check config server 0
$result = unJson(ric('admin info', $servers[0], 'admin'));
check(count($result['config']['servers'])===2, 'joinCluster failed servers of server[0]: '.join(',', $result['config']['servers']));

$response = ric('admin health', $servers[0], 'admin');
checkOk($response, 'health failed: '.$response);

ln('cluster is up and running');

#$result = unJson(Ric_Rest_Client::post($servers[2].'?',['action'=>'joinCluster','joinCluster'=>$servers[0],'token'=>'admin']));
#check($result['status']=='OK', 'joinServer failed for server '.$servers[2]);
#$result = unJson(Ric_Rest_Client::post($servers[3].'?',['action'=>'joinCluster','joinCluster'=>$servers[0],'token'=>'admin']));
#check($result['status']=='OK', 'joinServer failed for server '.$servers[3]);


