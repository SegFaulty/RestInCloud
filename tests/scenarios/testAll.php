<?php

chdir(__DIR__);
foreach( glob('test*.php') as $file ){
	if( $file==basename(__FILE__) ){
		continue; // skip me
	}
	echo 'EXECUTE: '.$file.PHP_EOL;
	include(__DIR__.'/'.$file);
}