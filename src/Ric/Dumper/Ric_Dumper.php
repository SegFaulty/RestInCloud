<?php

class Ric_Dumper {

	/**
	 * @param array $argv
	 * @param array $env
	 * @param string $helpString
	 * @return int
	 */
	static public function handleExecute($argv, $env, $helpString){
		$status = true;
		$msg = '';
		$cli = new Ric_Client_Cli($argv, $env);
		$cli->loadConfigFile($cli->getOption('configFile')); // load cofig file if present
		$command = array_shift($cli->arguments);
		if( $cli->getOption('verbose') ){
			self::dumpParameters($command, $cli);
		}

		try{
			$auth = $cli->getOption('auth', self::resolveSecretFile($cli->getOption('authFile')));
			$client = new Ric_Client_Client($cli->getOption('server'), $auth);
			$client->setDebug($cli->getOption('verbose'));
			$client->setCheckVersion(!$cli->getOption('ignoreVersion'));
			switch($command){
				case 'backup':
					$msg = self::commandBackup($client, $cli);
					break;
				case 'check':
					$msg = self::commandCheck($client, $cli);
					break;
				case 'list':
					$msg = self::commandList($client, $cli);
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
					$msg = self::getHelp(reset($cli->arguments), $helpString);
					break;
				default:
					throw new RuntimeException('command expected'.PHP_EOL.self::getHelp('', $helpString));

			}
			if( $cli->getOption('verbose') ){
				echo $client->getLog();
			}
		}catch(Exception $e){
			$status = 1;
			ini_set('display_errors', 'stderr'); // ensure we write to STDERR
			fwrite(STDERR, trim('ERROR: '.$e->getMessage()).PHP_EOL);
#			file_put_contents("php://stderr", rtrim($e->getMessage()).PHP_EOL);
		}

		if( !$cli->getOption('quite') ){
			echo rtrim($msg).PHP_EOL; // add trailing newline
		}
		if( is_bool($status) ){
			$status = (int) !$status;
		}
		return $status; // success -> 0 , failed >= 1
	}

	/**
	 * @param string $filePath
	 * @throws RuntimeException
	 * @return string
	 */
	static protected function resolveSecretFile($filePath){
		$secret = '';
		if( $filePath!='' ){
			if( !file_exists($filePath) OR !is_readable($filePath) ){
				throw new RuntimeException('authFile not found or not readable: '.$filePath);
			}
			$secret = trim(file_get_contents($filePath));
		}
		return $secret;
	}

	/**
	 * @param string $command
	 * @param string $helpString
	 * @return string
	 */
	static protected function getHelp($command = 'global', $helpString){
		if( $command and preg_match('~\n## Help '.preg_quote($command, '~').'(.*?)(\n## |$)~s', $helpString, $matches) ){
			$helpString = $matches[1];
		}
		$helpString = 'for server version: '.Ric_Client_Client::MIN_SERVER_VERSION.PHP_EOL.$helpString;
		return $helpString;
	}

	/**
	 * @param Ric_Client_Client $client
	 * @param Ric_Client_Cli $cli
	 * @return string
	 * @throws RuntimeException
	 */
	static protected function commandBackup($client, $cli){
		$resource = $cli->getArgument(1);
		if( count($cli->arguments)==1 ){
			if( is_file($resource) ){
				$targetFileName = basename($resource);
			}elseif( is_dir($resource) ){
				$targetFileName = basename($resource).'.tar.bz2';
			}else{
				throw new RuntimeException('no targetFileName given, but resource is not a regular file or dir!');
			}
		}else{
			$targetFileName = $cli->getArgument(2);
		}
		$targetFileName = $cli->getOption('prefix', '').$targetFileName;
		$password = $cli->getOption('pass', self::resolveSecretFile($cli->getOption('passFile')));
		$client->backup($resource, $targetFileName, $password, $cli->getOption('retention'), $cli->getOption('timestamp'), $cli->getOption('minReplicas'), $cli->getOption('minSize'));
		return 'OK'.PHP_EOL.$targetFileName;
	}

}