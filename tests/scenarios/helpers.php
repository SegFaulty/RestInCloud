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
 */
function check($condition, $description=''){
	if( !$condition ){
		ln($description);
		exit;
	}
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