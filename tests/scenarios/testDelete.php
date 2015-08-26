<?php

include __DIR__.'/setupCluster.php';

ln('start test delete');

ln('upload a file, to the cluster, delete it, must returns 3 deleted file');
// upload a file
checkOK(Ric_Rest_Client::putFile($servers[0].'/testfile.txt?token=admin',__FILE__));

$result = unJson(Ric_Rest_Client::delete($servers[0].'/testfile.txt?token=admin',__FILE__));
check($result['filesDeleted']=='3', 'delete failed testfile, expected 3 (because of three servers) response:'.print_r($result, true));

ln('upload 2 versions test delete with empty version');

$tesFileContent = 'version1';
checkOK(Ric_Rest_Client::put($servers[0].'/testfile.txt?token=admin', $tesFileContent));
$tesFileContent = 'version100';
checkOK(Ric_Rest_Client::put($servers[0].'/testfile.txt?token=admin', $tesFileContent));
$result = unJson(Ric_Rest_Client::delete($servers[0].'/testfile.txt?token=admin',__FILE__));
check($result['filesDeleted']=='6', 'delete failed for two testfiles, expected 6 (because of three servers) response:'.print_r($result, true));

ln('upload 2 versions test delete a selected version');
$tesFileContent = 'version2';
checkOK(Ric_Rest_Client::put($servers[0].'/testfile.txt?token=admin', $tesFileContent));
$tesFileContent = 'version3';
checkOK(Ric_Rest_Client::put($servers[0].'/testfile.txt?token=admin', $tesFileContent));
$result = unJson(Ric_Rest_Client::get($servers[0].'/testfile.txt?list&token=admin', $tesFileContent));
check(count($result)==2 and isset($result[0]['version']), 'expected 2 file versions! got:'.print_r($result, true));
$version = $result[0]['version'];
// delete selected version
$result = unJson(Ric_Rest_Client::delete($servers[0].'/testfile.txt?version='.$version.'&token=admin',__FILE__));
check($result['filesDeleted']=='3', 'delete failed for one testfile, expected 3 response:'.print_r($result, true));
// check deleted
$result = unJson(Ric_Rest_Client::get($servers[0].'/testfile.txt?list&token=admin', $tesFileContent));
check(count($result)==1 and isset($result[0]['version']), 'expected 1 file versions! got:'.print_r($result, true));
// delete remaining
$result = unJson(Ric_Rest_Client::delete($servers[0].'/testfile.txt?token=admin',__FILE__));
check($result['filesDeleted']=='3', 'delete failed for rest testfiles, expected 3  response:'.print_r($result, true));


ln('delete test passed');

include __DIR__.'/tearDownCluster.php';