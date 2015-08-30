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
 * @param string $server
 * @param string $auth
 * @param int $expectedStatus
 * @param bool $stderr
 * @return string
 */
function ric($command,  $server, $auth, &$expectedStatus=0, &$stderr=false){
	$command = '../../src/Ric/Client/ric-cli '.$command.' --server='.$server.' --auth='.$auth;
	$returnCode = sh($command, $stdout, $stderr);
	if( $expectedStatus!==$returnCode ){
		ln('command failed: '.$command.PHP_EOL.' status:'.$returnCode.PHP_EOL.' stdout:'.$stdout.PHP_EOL.' stderr:'.$stderr.PHP_EOL);
		exit;
	}
	return $stdout;
}

function sh($cmd, &$stdout=null, &$stderr=null) {
	$proc = proc_open($cmd,[
			1 => ['pipe','w'],
			2 => ['pipe','w'],
	],$pipes);
	$stdout = stream_get_contents($pipes[1]);
	fclose($pipes[1]);
	$stderr = stream_get_contents($pipes[2]);
	fclose($pipes[2]);
	return proc_close($proc);
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
 */
function checkOk($responseOrResult, $description='Status Ok expected but got {$response}', $occuredAt=''){
	if( $occuredAt=='' ){
		$backTrace = debug_backtrace();
		$occuredAt = basename($backTrace[0]['file']).' line: '.$backTrace[0]['line'];
	}
	$description = str_replace('{$response}', var_export($responseOrResult, true), $description);
	if( is_string($responseOrResult) ){
		$responseOrResult = unJson($responseOrResult);
	}
	check(isset($responseOrResult['status']) AND $responseOrResult['status']=='OK', $description, $occuredAt);
}

/**
 * @param string $response
 * @return array
 */
function unJson($response){
	$result = json_decode($response, true);
	$backTrace = debug_backtrace();
	$occuredAt = basename($backTrace[0]['file']).' line: '.$backTrace[0]['line'];
	check(is_array($result), 'failed! response:'.$response, $occuredAt);
	return $result;
}