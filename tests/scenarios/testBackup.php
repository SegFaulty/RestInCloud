<?php

include __DIR__.'/setupCluster.php';

ln('start backup delete');

ln('upload a file, to the cluster');
// upload a file
$tesFileContent = 'version100';
checkOK(Ric_Rest_Client::put($servers[0].'/testfile.txt?token=admin', $tesFileContent));

ln('check file');
checkOk(Ric_Rest_Client::get($servers[0].'/testfile.txt?check&token=admin'));

ln('test restore');
$response = Ric_Rest_Client::get($servers[0].'/testfile.txt?&token=admin');
check($response===$tesFileContent, 'file content is not identically');

// delete remaining
$result = unJson(Ric_Rest_Client::delete($servers[0].'/testfile.txt?token=admin'));
check($result['filesDeleted']=='3', 'delete failed for rest testfiles, expected 3  response:'.print_r($result, true));

ln('test fail with retention at post');
$result = unJson(Ric_Rest_Client::post($servers[0].'/testfile.txt?retention=5l&token=admin'));
check($result['error']=='parameter retention is not allowed on post action', 'failed: post with retention is not okay '.print_r($result, true));


ln('backup test passed');

include __DIR__.'/tearDownCluster.php';