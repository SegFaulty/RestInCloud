<?php

include __DIR__.'/setupCluster.php';

ln('start test double join protection');

ln('join '.$servers[1].' to cluster at '.$servers[0]);

// join server 1 to 0
$response = Ric_Rest_Client::post($servers[1].'?', ['action' => 'joinCluster', 'joinCluster' => $servers[0], 'token' => 'admin']);
checkOk($response, 'joinServer failed for server '.$servers[1].' response: '.$response);

$result = unJson(Ric_Rest_Client::get($servers[0].'?info', ['token' => 'admin']));
#print_r($result['config']['servers']);
check(count($result['config']['servers'])===2, 'joinCluster failed servers of server[0]: '.join(',', $result['config']['servers']));

$response = Ric_Rest_Client::get($servers[0].'?health', ['token' => 'admin']);
checkOk($response, 'joinCluster failed health: '.$response);

ln('double join protection test passed');

include __DIR__.'/tearDownCluster.php';
