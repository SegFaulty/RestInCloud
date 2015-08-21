<?php

chdir(__DIR__);

$ricFileContent = '';

// get ric-cli part
$ricCli = file_get_contents('ric-cli');
$ricCli = preg_replace('~^\s*require_once .*$~m', '', $ricCli);
$ricFileContent.= $ricCli;

$ricFileContent.= '// build: '.date('Y-m-d H:i:s').PHP_EOL.PHP_EOL;

$ricFileContent.= PHP_EOL.'# CliHandler.php'.PHP_EOL;
$ricFileContent.= substr(file_get_contents('CliHandler.php'),5);
$ricFileContent.= PHP_EOL.'# Client.php'.PHP_EOL;
$ricFileContent.= substr(file_get_contents('Client.php'),5);
$ricFileContent.= PHP_EOL.'# Cli.php'.PHP_EOL;
$ricFileContent.= substr(file_get_contents('Cli.php'),5);
$ricFileContent.= PHP_EOL.'# ../Rest/Client.php'.PHP_EOL;
$ricFileContent.= substr(file_get_contents('../Rest/Client.php'),5);

$ricFileContent = str_replace('Ric_Client_', 'RC_', $ricFileContent); // replace duplicate class names to prevent ide confusion
$ricFileContent = str_replace('Ric_Rest_', 'RR_', $ricFileContent); // replace duplicate class names to prevent ide confusion

$ricFileContent.= '/*'.PHP_EOL;
$ricFileContent.=  PHP_EOL.'####### README.md #######'.PHP_EOL;
$ricFileContent.= file_get_contents('README.md');
$ricFileContent.=  PHP_EOL.'####### README.md #######'.PHP_EOL;
$ricFileContent.= '*/'.PHP_EOL;

file_put_contents('ric', $ricFileContent);

echo 'done ... PLEASE CHECK test it for php errors:  ./ric help --verbose'.PHP_EOL;
