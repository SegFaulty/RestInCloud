<?php

include __DIR__.'/setupCluster.php';

ln('start push test');

ln('upload a file, in two versions');

$tesFileContent1 = 'version1';
$result = unJson(Ric_Rest_Client::put($servers[0].'/testfile.txt?token=admin', $tesFileContent1));
checkOK($result);
$version1 = $result['version'];
$timestamp1 = $result['timestamp'];
sleep(1);
$tesFileContent2 = 'version100';
$result = unJson(Ric_Rest_Client::put($servers[0].'/testfile.txt?token=admin', $tesFileContent2));
checkOK($result);
$version2 = $result['version'];
$timestamp2 = $result['timestamp'];

ln('add the 4th server to cluster');
$response = Ric_Rest_Client::post($servers[3],['action'=>'joinCluster','joinCluster'=>$servers[0],'token'=>'admin']);
checkOk($response, 'joinServer failed for server '.$servers[1]);

ln('push old version to new server');
$response = Ric_Rest_Client::post($servers[0].'/testfile.txt',['action'=>'push','version'=>$version2,'server'=>$servers[3],'token'=>'admin']);
checkOk($response, 'push failed:'.$response);

$result = unJson(Ric_Rest_Client::get($servers[3].'/testfile.txt?list&token=admin'));
check(count($result)==1, 'unexpected version count');
check($result[0]['sha1']==sha1($tesFileContent2), 'not the expected content');

ln('test push fails if no versin given');
$result =  unJson(Ric_Rest_Client::post($servers[0].'/testfile.txt',['action'=>'push','server'=>$servers[3],'token'=>'admin']));
check(isset($result['error']), 'failed error test, expected is an error: version required ');

ln('push latest version');
$response = Ric_Rest_Client::post($servers[0].'/testfile.txt',['action'=>'push','version'=>$version1,'server'=>$servers[3],'token'=>'admin']);
checkOk($response, 'push failed:'.$response);

$result = unJson(Ric_Rest_Client::get($servers[3].'/testfile.txt?list&token=admin'));
check(count($result)==2, 'expected to version but got:'.print_r($result, true));
check($result[0]['sha1']==sha1($tesFileContent2), 'not the expected content on newest version - expected:'.sha1($tesFileContent2));
check($result[0]['timestamp']==$timestamp2, 'not the expected timestamp on newest version - expected:'.$timestamp2);
check($result[1]['timestamp']==$timestamp1, 'not the expected timestamp on second version - expected:'.$timestamp1);


// delete remaining
$result = unJson(Ric_Rest_Client::delete($servers[0].'/testfile.txt?token=admin',__FILE__));
check($result['filesDeleted']=='8', 'delete failed for rest testfiles, expected 8 response:'.print_r($result, true));
print_r($result);

ln('push test passed');

include __DIR__.'/tearDownCluster.php';