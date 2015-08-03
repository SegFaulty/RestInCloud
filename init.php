<?php

// Helper syntactic sugar function
class H{
    /* getKeyIfSet */ static public function getIKS(&$array, $key, $default=null){return (array_key_exists($key, $array) ? $array[$key] : $default );}
    /* getRequestParameter */ static public function getRP($key, $default=null){return H::getIKS($_REQUEST, $key, $default);}
    /* json_encode */ static public function json($array){return json_encode($array, JSON_PRETTY_PRINT+JSON_UNESCAPED_SLASHES).PHP_EOL;}
    /* implodeKeyValue */ 	static public function implodeKeyValue($inputArray,$delimiter=', ',$keyValueDelimiter=': '){implode($delimiter,array_map(function($k,$v) use ($keyValueDelimiter)  {return $k.$keyValueDelimiter.$v;},array_keys($inputArray),$inputArray));}
}

set_include_path(get_include_path().PATH_SEPARATOR.realpath(__DIR__.'/src'));
spl_autoload_register(function($class){
    $filePath = $class;
    $filePath = str_replace('\\', '/', $filePath);
    $filePath = str_replace('_', '/', $filePath);
    $filePath.= '.php';
    require_once $filePath;
});

