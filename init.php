<?php

define('PROJECT_ROOT', __DIR__.'/');

require_once PROJECT_ROOT.'src/Ric/Server/Helper/SyntacticSugar.php';

set_include_path(get_include_path().PATH_SEPARATOR.realpath(PROJECT_ROOT.'/src'));
spl_autoload_register(function ($class){
	$filePath = $class;
	$filePath = str_replace('\\', '/', $filePath);
	$filePath = str_replace('_', '/', $filePath);
	$filePath .= '.php';
	require_once $filePath;
});

