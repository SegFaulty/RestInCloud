<?php

$readonly = ini_get('phar.readonly');
if( $readonly && 'off'!==strtolower($readonly) ){
	die(".phar creation disabled in php.ini!\nRe-run with \nphp -d phar.readonly=0 ".$argv[0]."\n");
}

$pharFile = 'dumper.phar';
if( file_exists($pharFile) ){
	unlink($pharFile);
}

$phar = new Phar($pharFile, 0, $pharFile);
$phar->setSignatureAlgorithm(Phar::SHA1);
$phar->startBuffering();
$phar->addFile('../Dumper.php', 'Dumper.php');
$phar->addFile('../../Client/Cli.php', 'Cli.php');
$phar->addFile('../../Server/Helper/SyntacticSugar.php', 'SyntacticSugar.php');
$phar->addFile('../README.md', 'README.md');
$stub = file_get_contents(__DIR__.'/dumper-phar-stub.php');
$phar->setStub($stub);
$phar->compressFiles(Phar::GZ);
$phar->stopBuffering();
unset($phar);

chmod($pharFile, 0755);
echo 'done... test with:'.PHP_EOL;
echo './'.$pharFile.' help --verbose'.PHP_EOL;