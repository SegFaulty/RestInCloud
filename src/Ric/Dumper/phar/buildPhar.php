<?php

$readonly = ini_get('phar.readonly');
if( $readonly && 'off'!==strtolower($readonly) ){
	die(".phar creation disabled in php.ini!\nRe-run with \nphp -d phar.readonly=0 ".$argv[0]."\n");
}

$pharFile = __DIR__.'/dumper.phar';
if( file_exists($pharFile) ){
	unlink($pharFile);
}

$phar = new Phar($pharFile, 0, 'dumper.phar');
$phar->setSignatureAlgorithm(Phar::SHA1);
$phar->startBuffering();
$phar->addFile(__DIR__.'/../Dumper.php', 'Dumper.php');
$phar->addFile(__DIR__.'/../../Client/Cli.php', 'Cli.php');
$phar->addFile(__DIR__.'/../../Server/Helper/SyntacticSugar.php', 'SyntacticSugar.php');
$phar->addFile(__DIR__.'/../README.md', 'README.md');
$stub = file_get_contents(__DIR__.'/dumper-phar-stub.php');
$stub = str_replace('__LIVE__', date('Y-m-d H:i:s'), $stub); // inject build-date
$phar->setStub($stub);
$phar->compressFiles(Phar::GZ);
$phar->stopBuffering();
unset($phar);

chmod($pharFile, 0755);
echo 'done... test with:'.PHP_EOL;
echo $pharFile.' help --verbose'.PHP_EOL;