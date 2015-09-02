<?php

$readonly = ini_get('phar.readonly');
if( $readonly && 'off'!==strtolower($readonly) ){
	die(".phar creation disabled in php.ini!\nRe-run with \nphp -d phar.readonly=0 ".$argv[0]."\n");
}

$pharFile = 'ric.phar';
if( file_exists($pharFile) ){
	unlink($pharFile);
}

$phar = new Phar($pharFile, 0, $pharFile);
$phar->setSignatureAlgorithm(Phar::SHA1);
$phar->startBuffering();
$phar->addFile('../Cli.php', 'Cli.php');
$phar->addFile('../CliHandler.php', 'CliHandler.php');
$phar->addFile('../Client.php', 'Client.php');
$phar->addFile('../../Rest/Client.php', 'RestClient.php');
$phar->addFile('../../Server/Helper/SyntacticSugar.php', 'SyntacticSugar.php');
$stub = file_get_contents(__DIR__.'/ric-phar-stub.php');
$phar->setStub($stub);
$phar->compressFiles(Phar::GZ);
$phar->stopBuffering();
unset($phar);

echo 'done... test with:'.PHP_EOL;
echo './ric.phar help --verbose'.PHP_EOL;