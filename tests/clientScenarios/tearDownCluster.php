<?php

require_once __DIR__.'/init.php';

ln('tear down cluster');
$response = ric('admin leaveCluster', $servers[0], 'admin');
checkOk($response, 'leaveCluster failed for server '.$servers[0]);
$response = ric('admin leaveCluster', $servers[1], 'admin');
checkOk($response, 'leaveCluster failed for server '.$servers[1]);

// check third server, must be standalone
$result = unJson(ric('admin info', $servers[2], 'admin'));
check(count($result['config']['servers'])===0, 'Server is already joined in a cluster something failed: '.join(',', $result['config']['servers']));
ln('all servers leaved the cluster');

foreach( $servers as $server ){
	checkOk(ric('delete testFile.txt all', $server, 'admin'));
	checkOk(ric('delete testFile2.txt all', $server, 'admin'));
	checkOk(ric('delete testfile.txt all', $server, 'admin'));
	checkOk(ric('delete testfile2.txt all', $server, 'admin'));
}