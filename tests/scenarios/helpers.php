<?php


/**
 * @param $msg
 */
function ln($msg){
	$mt = microtime(true);
	echo date('H:i:s', $mt).':'.(round($mt-intval($mt),3)*1000);
	echo ' '.$msg."\n";
}

/**
 * @param string $command
 * @param bool $ignoreFailed
 * @return string
 */
function sh($command, $ignoreFailed=false){
	exec($command, $output, $returnCode);
	if( $returnCode AND !$ignoreFailed ){
		ln('command: '.$command.' failed with code:'.$returnCode.' output:'.join("\n", $output));
		exit;
	}
	return join("\n", $output)."\n";
}

/**
 * @param bool $condition
 * @param string $description
 * @param string $occuredAt
 */
function check($condition, $description='', $occuredAt=''){
	if( $occuredAt=='' ){
		$backTrace = debug_backtrace();
#print_r($backTrace);
		$occuredAt = basename($backTrace[0]['file']).' line: '.$backTrace[0]['line'];
	}
	if( !$condition ){
		ln('CHECK FAILED: '.$description.' @ '.$occuredAt);
		exit;
	}
}

/**
 * check if look like ok, if SLND-468 is fixed, check only for Status->OK
 * @param string | array $responseOrResult
 * @param string $description
 * @param string $occuredAt
 * @internal param bool $condition
 */
function checkOk($responseOrResult, $description='Status Ok expected but got {$response}', $occuredAt=''){
	if( $occuredAt=='' ){
		$backTrace = debug_backtrace();
		$occuredAt = basename($backTrace[0]['file']).' line: '.$backTrace[0]['line'];
	}
	$description = str_replace('{$response}', var_export($responseOrResult, true), $description);
	check(trim($responseOrResult)==='OK' OR trim($responseOrResult)==='["Status"=>"OK"]' OR $responseOrResult = ['Status'=>'OK'], $description, $occuredAt);
}

/**
 * @param string $response
 * @return array
 */
function unJson($response){
	$result = json_decode($response, true);
	check(is_array($result), 'failed! response:'.$response);
	return $result;
}