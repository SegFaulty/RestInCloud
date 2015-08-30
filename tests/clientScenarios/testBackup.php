<?php

include __DIR__.'/setupCluster.php';

ln('start backup delete');

ln('upload a file, to the cluster');
// upload a file
$tesFileContent = 'version100';
checkOks(ric('backup '.__FILE__.' testFile.txt', $servers[0], 'writer'));

ln('check file');
checkOks(ric('check testFile.txt', $servers[0], 'writer'));

// delete remaining
$result = unJson(ric('delete testFile.txt all', $servers[0], 'admin'));
check($result['filesDeleted']=='3', 'delete failed for rest testfiles, expected 3  response:'.print_r($result, true));


ln('backup test passed');

include __DIR__.'/tearDownCluster.php';