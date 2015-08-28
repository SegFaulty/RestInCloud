<?php

require_once __DIR__.'/init.php';

ln('tear down cluster');
$result = unJson(Ric_Rest_Client::post($servers[0].'?',['action'=>'leaveCluster','token'=>'admin']));
check($result['status']=='OK', 'leaveCluster failed for server '.$servers[0]);
$result = unJson(Ric_Rest_Client::post($servers[1].'?',['action'=>'leaveCluster','token'=>'admin']));
check($result['status']=='OK', 'leaveCluster failed for server '.$servers[1]);
// check third server, must be standalone
$result = unJson(Ric_Rest_Client::get($servers[2].'?info',['token'=>'admin']));
check(count($result['config']['servers'])===0, 'Server is already joined in a cluster something failed: '.join(',', $result['config']['servers']));
ln('all servers leaved the cluster');

