<?php

include __DIR__.'/setupCluster.php';

ln('start backup delete');

ln('upload a file, to the cluster');
// upload a file
$tesFileContent = 'version100';
checkOK(Ric_Rest_Client::put($servers[0].'/testfile.txt?token=admin', $tesFileContent));

ln('check file');
checkOk(Ric_Rest_Client::get($servers[0].'/testfile.txt?check&token=admin'));

ln('check minSize');
checkOk(Ric_Rest_Client::get($servers[0].'/testfile.txt?check&minSize=5&token=admin'));
$result = unJson(Ric_Rest_Client::get($servers[0].'/testfile.txt?check&minSize=50&token=admin'));
check($result['status']=='CRITICAL', 'check failed, no error reported for minSize file:'.print_r($result, true));
check($result['msg']=='size less then expected (10/50)', 'check failed, no error reported for minSize file:'.print_r($result, true));

ln('check minReplicas');
checkOk(Ric_Rest_Client::get($servers[0].'/testfile.txt?check&minReplicas=2&token=admin'));
$result = unJson(Ric_Rest_Client::get($servers[0].'/testfile.txt?check&minReplicas=3&token=admin'));
check($result['status']=='WARNING', 'check failed, no error reported for minReplicas file:'.print_r($result, true));
check($result['msg']=='not enough replicas (2/3)', 'check failed, no error reported for minReplicas file:'.print_r($result, true));

// check for non existing
$result = unJson(Ric_Rest_Client::get($servers[0].'/test________file.txt?check&token=admin'));
check($result['error']=='no version of file not found', 'check failed, no error reported for nonexisting file:'.print_r($result, true));

// delete remaining
$result = unJson(Ric_Rest_Client::delete($servers[0].'/testfile.txt?token=admin'));
check($result['filesDeleted']=='3', 'delete failed for rest testfiles, expected 3  response:'.print_r($result, true));



ln('backup test passed');

include __DIR__.'/tearDownCluster.php';