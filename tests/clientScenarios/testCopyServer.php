<?php

include __DIR__.'/setupCluster.php';

ln('start copyServer test');

ln('upload a files, to the cluster');
// upload a file
$testFilePath = PROJECT_ROOT.'var/testClientScenarioFile.txt';
if( file_exists($testFilePath) ){
	unlink($testFilePath);
}
$testFileContent = 'version100';
file_put_contents($testFilePath, $testFileContent);
checkOks(ric('backup '.$testFilePath.' testFile.txt', $servers[0], 'writer'));
sleep(1);
$testFileContent = 'version200';
file_put_contents($testFilePath, $testFileContent);
checkOks(ric('backup '.$testFilePath.' testFile.txt', $servers[0], 'writer'));
$testFileContent = 'version200';
file_put_contents($testFilePath, $testFileContent);
checkOks(ric('backup '.$testFilePath.' testFile2.txt', $servers[0], 'writer'));

ln('copy server');
$result = unJson(ric('admin copyServer '.$servers[3], $servers[0], 'admin'));
checkOk($result);
check($result['filesCopied']==3, 'expected 2 copied Files result: '.print_r($result, true));

$result = ric('admin list', $servers[3], 'admin');
$files = array_filter(explode("\n", $result));
check($files==['testFile2.txt', 'testFile.txt'], 'copy to new server failed: files found:'.print_r($files, true));

// delete remaining
$result = unJson(ric('delete testFile.txt all', $servers[0], 'admin'));
checkOk($result, 'delete failed'.print_r($result, true));
$result = unJson(ric('delete testFile2.txt all', $servers[0], 'admin'));
checkOk($result, 'delete failed'.print_r($result, true));

ln('copyServer test passed');

include __DIR__.'/tearDownCluster.php';
