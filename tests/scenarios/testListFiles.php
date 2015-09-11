<?php

include __DIR__.'/setupCluster.php';

ln('start test listFiles');

ln('upload files, to the cluster, list fileNames');
ln('upload 2 versions test delete with empty version');
$tesFileContent = 'version1';
checkOK(Ric_Rest_Client::put($servers[0].'/testfile.txt?token=admin', $tesFileContent));
$tesFileContent = 'version100';
checkOK(Ric_Rest_Client::put($servers[0].'/testfile.txt?token=admin', $tesFileContent));
checkOK(Ric_Rest_Client::put($servers[0].'/testfile2.txt?token=admin', $tesFileContent));

$result = unJson(Ric_Rest_Client::get($servers[0].'/?list&token=admin'));
check(['testfile.txt', 'testfile2.txt']===$result, 'unexpected result: '.print_r($result, true));

// delete remaining
$result = unJson(Ric_Rest_Client::delete($servers[0].'/testfile.txt?token=admin', __FILE__));
checkOk($result, 'delete failed response:'.print_r($result, true));

ln('change upload order, because of list order issues');
$tesFileContent = 'version100';
checkOK(Ric_Rest_Client::put($servers[0].'/testfile.txt?token=admin', $tesFileContent));
checkOK(Ric_Rest_Client::put($servers[0].'/testfile2.txt?token=admin', $tesFileContent));
$tesFileContent = 'version1';
checkOK(Ric_Rest_Client::put($servers[0].'/testfile.txt?token=admin', $tesFileContent));

$result = unJson(Ric_Rest_Client::get($servers[0].'/?list&token=admin'));
check(['testfile.txt', 'testfile2.txt']===$result, 'unexpected result: '.print_r($result, true));

// delete remaining
$result = unJson(Ric_Rest_Client::delete($servers[0].'/testfile.txt?token=admin', __FILE__));
checkOk($result, 'delete failed response:'.print_r($result, true));

ln('test list with pattern (regex $testfile2$)');
$result = unJson(Ric_Rest_Client::get($servers[0].'/?list&pattern=$testfile2$&token=admin')); // regex $testfile2$
check(['testfile2.txt']===$result, 'unexpected result: '.print_r($result, true));


ln('delete test passed');

include __DIR__.'/tearDownCluster.php';