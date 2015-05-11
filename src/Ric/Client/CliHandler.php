<?php

/**
 * encryption
 * http://www.shellhacks.com/en/Encrypt-And-Decrypt-Files-With-A-Password-Using-OpenSSL
 *
 *
 *
 * Class Ric_Client_CliHandler
 */
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
		if( $cli->getOption('verbose') ){
			echo 'command: '.$command.PHP_EOL;
			echo 'arguments: ';
			foreach( $cli->arguments as $value ){
				echo '"'.$value.'", ';
			}
			echo PHP_EOL;
			echo 'options: ';
			foreach( $cli->options as $key=>$value ){
				echo $key.': "'.$value.'", ';
			}
			echo PHP_EOL;
			echo 'environment: ';
			foreach( $cli->env as $key=>$value ){
				echo $key.': "'.$value.'", ';
			}
			echo PHP_EOL;
		}

		try{
			$client = new Ric_Client_Client($cli->getOption('server'), $cli->getOption('auth'));
			$client->setDebug($cli->getOption('verbose'));
			switch($command){
				case 'backup':
					$msg = self::commandBackup($client, $cli);
					break;
				case 'verify':
					$msg = self::commandVerify($client, $cli);
					break;
				case 'restore':
					$msg = self::commandRestore($client, $cli);
					break;
				case 'delete':
					$msg = self::commandDelete($client, $cli);
					break;
				case 'admin':
					$msg = self::commandAdmin($client, $cli);
					break;
				case 'help':
					$msg = $client->getHelp(reset($cli->arguments));
					break;
				default:
					throw new RuntimeException('command expected'.PHP_EOL.$client->getHelp());

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
	 * @param Ric_Client_Client $client
	 * @param Ric_Client_Cli $cli
	 * @return string
	 * @throws RuntimeException
	 */
	static protected function commandVerify($client, $cli){
		$targetFileName = $cli->arguments[0];
		$client->verify($targetFileName, $cli->getOption('minReplicas'), $cli->getOption('sha1'), $cli->getOption('minSize'), $cli->getOption('minTimestamp'));
		return 'OK';
	}

	/**
	 * @param Ric_Client_Client $client
	 * @param Ric_Client_Cli $cli
	 * @return string
	 * @throws RuntimeException
	 */
	static protected function commandRestore($client, $cli){
		$targetFileName = $cli->arguments[0];
		if( count($cli->arguments[0])==1 ){
			$resource = getcwd().'/'.basename($targetFileName);
		}else{
			$resource = $cli->arguments[1];
		}
		$client->restore($targetFileName, $resource, $cli->getOption('version'));
		return 'OK';
	}

	/**
	 * @param Ric_Client_Client $client
	 * @param Ric_Client_Cli $cli
	 * @return string
	 * @throws RuntimeException
	 */
	static protected function commandDelete($client, $cli){
		$targetFileName = $cli->arguments[0];
		return $client->delete($targetFileName, $cli->getOption('version'));
	}

	/**
	 * @param Ric_Client_Client $client
	 * @param Ric_Client_Cli $cli
	 * @return string
	 * @throws RuntimeException
	 */
	static protected function commandAdmin($client, $cli){
		$msg = '';
		if( count($cli->arguments)==0 ){
			throw new RuntimeException('admin command expected'.PHP_EOL.$client->getHelp('admin'));
		}
		$adminCommand = $cli->arguments[0];
		if( $adminCommand=='info' ){
			$msg = json_encode($client->info(), JSON_PRETTY_PRINT);
		}elseif( $adminCommand=='health' ){
			$msg = $client->health();
		}elseif( $adminCommand=='addServer' ){
			$msg = $client->addServer($cli->arguments[1]);
		}elseif( $adminCommand=='removeServer' ){
			$msg = $client->removeServer($cli->arguments[1]);
		}elseif( $adminCommand=='joinCluster' ){
			$msg = $client->joinCluster($cli->arguments[1]);
		}elseif( $adminCommand=='leaveCluster' ){
			$msg = $client->leaveCluster();
		}else{
			throw new RuntimeException('unknown admin command');
		}
		return $msg;
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
