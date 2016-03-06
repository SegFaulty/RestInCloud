<?php

// Helper syntactic sugar function
class H{
/* getKeyIfSet */
	/**
	 * Note: No notice is raised if the variable is not defined
	 * and the variable is still not defined after this step
	 * @param array $array
	 * @param int|string|int[]|string[] $keys
	 * @param mixed $default
	 * @return mixed
	 */
	static public function getIKS(&$array, $keys, $default=null){
		$result = $default;
		$keys = (array) $keys;
		$key = array_shift($keys);
		if( array_key_exists($key, $array) ){
			$result = $array[$key];
			if( count($keys) ){
				$result = self::getIKS($result, $keys, $default);
			}
		}
		return $result;
	}
/* getRequestParameter */ static public function getRP($key, $default=null){return H::getIKS($_REQUEST, $key, $default);}
/* json_encode */ static public function json($array){return json_encode($array, JSON_PRETTY_PRINT+JSON_UNESCAPED_SLASHES).PHP_EOL;}
/* implodeKeyValue */ 	static public function implodeKeyValue($inputArray,$delimiter=', ',$keyValueDelimiter=': '){implode($delimiter,array_map(function($k,$v) use ($keyValueDelimiter)  {return $k.$keyValueDelimiter.$v;},array_keys($inputArray),$inputArray));}
}