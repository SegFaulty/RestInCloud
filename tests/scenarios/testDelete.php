<?php

include __DIR__.'/setupCluster.php';

ln('start test delete');

ln('upload a file, to the cluster, delete it, must returns 3 deleted file');
// upload a file
$response = trim(Ric_Rest_Client::putFile($servers[0].'/testfile.txt?token=admin',__FILE__));
check($response=='OK', 'upload failed testfile for server response:'.$response);

$result = unJson(Ric_Rest_Client::delete($servers[0].'/testfile.txt?token=admin',__FILE__));
check($result['filesDeleted']=='3', 'delete failed testfile, expected 3 (because of three servers) response:'.$response);

// todo upload 2 versions test empty version
// todo upload 2 version delete one version


ln('delete test passed');

include __DIR__.'/tearDownCluster.php';