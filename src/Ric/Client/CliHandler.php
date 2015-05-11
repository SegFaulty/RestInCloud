<?php

class Ric_Client_CliHandler{

	/**
	 * @param array $argv
	 * @param $env
	 * @return int
	 */
	static public function handleExecute($argv, $env){
		$status = true;
		$msg = '';
		$cli = new Ric_Client_Cli($argv, $env);
		$command = array_shift($cli->arguments);
		try{
			$client = new Ric_Client_Client($cli->getOption('server'), $cli->getOption('auth'));
			$client->setDebug($cli->getOption('verbose'));
			switch($command){
				case 'backup':
					$msg = self::commandBackup($client, $cli);
					break;
				case 'help':
					$msg = $client->getHelp(reset($cli->arguments));
					break;
				default:
					throw new RuntimeException('command not found');
			}
			if( $cli->getOption('verbose') ){
				echo $client->getLog();
			}
		}catch(Exception $e){
			$status = 1;
			$msg = $e->getMessage();
		}

		echo rtrim($msg).PHP_EOL; // add trailing newline
		if( is_bool($status) ){
			$status = (int) !$status;
		}
		return $status; // success -> 0 , failed >= 1
	}

	/**
	 * @param Ric_Client_Client $client
	 * @param Ric_Client_Cli $cli
	 * @return string
	 * @throws RuntimeException
	 */
	static protected function commandBackup($client, $cli){
		$resource = $cli->arguments[0];
		if( count($cli->arguments[0])==1 ){
			if( is_file($resource) ){
				$targetFileName = basename($resource);
			}else{
				throw new RuntimeException('no targetFileName given, but resource is not a regular file!');
			}
		}else{
			$targetFileName = $cli->arguments[1];
		}
		$client->backup($resource, $targetFileName, $cli->getOption('retention'), $cli->getOption('timestamp'), $cli->getOption('minReplicas'), $cli->getOption('minSize'));
		return 'OK';
	}

	/**
	 * @param array $arguments
	 * @param array $options
	 * @return array [$success, $msg]
	 */
	public function testTrue($arguments, $options ){
		return 'alles fein';
	}

	/**
	 * @param array $arguments
	 * @param array $options
	 * @return array [$success, $msg]
	 */
	public function testFalse($arguments, $options ){
		return [false, 'fehler false triggered'];
	}

	/**
	 * @param array $arguments
	 * @param array $options
	 * @return array [$success, $msg]
	 */
	public function testCli($arguments, $options ){
		$msg = 'cli:'.PHP_EOL;
		$msg.= '$arguments:'.print_r($arguments, true).PHP_EOL;
		$msg.= '$options:'.print_r($options, true).PHP_EOL;
		$msg.= '$_SERVER:'.print_r($_SERVER, true).PHP_EOL;
		$msg.= '$_ENV:'.print_r($_ENV, true).PHP_EOL;
		return [true, $msg];
	}

}
