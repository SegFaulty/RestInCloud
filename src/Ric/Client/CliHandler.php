<?php

/**
 * todo check for to many arguments, helperFunction ?
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
		$cli = new Ric_Client_Cli($argv, $env);
		$command = array_shift($cli->arguments);
		if( $cli->getOption('verbose') ){
			self::dumpParameters($command, $cli);
		}

		try{
			$auth = $cli->getOption('auth', self::resolveSecretFile($cli->getOption('authFile')));
			$client = new Ric_Client_Client($cli->getOption('server'), $auth);
			$client->setDebug($cli->getOption('verbose'));
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
		$targetFileName = $cli->getOption('prefix','').$targetFileName;
		$password = $cli->getOption('pass', self::resolveSecretFile($cli->getOption('passFile')));
		$client->backup($resource, $targetFileName, $password, $cli->getOption('retention'), $cli->getOption('timestamp'), $cli->getOption('minReplicas'), $cli->getOption('minSize'));
		return 'OK'.PHP_EOL.$targetFileName;
	}

	/**
	 * @param Ric_Client_Client $client
	 * @param Ric_Client_Cli $cli
	 * @return string
	 * @throws RuntimeException
	 */
	static protected function commandCheck($client, $cli){
		$targetFileName = $cli->arguments[0];
		$targetFileName = $cli->getOption('prefix','').$targetFileName;
		$client->check($targetFileName, $cli->getOption('minReplicas'), $cli->getOption('sha1'), $cli->getOption('minSize'), $cli->getOption('minTimestamp'));
		return 'OK';
	}

	/**
	 * @param Ric_Client_Client $client
	 * @param Ric_Client_Cli $cli
	 * @return string
	 * @throws RuntimeException
	 */
	static protected function commandList($client, $cli){
		$msg = '';
		$targetFileName = $cli->arguments[0];
		$targetFileName = $cli->getOption('prefix','').$targetFileName;
		$versions = $client->versions($targetFileName);
		$msg.= $targetFileName.PHP_EOL;
		$msg.= 'Date       Time     Version (sha1)                           Size'.PHP_EOL;
		$msg.= '----------|--------|----------------------------------------|--------------'.PHP_EOL;
		$size = 0;
		foreach( $versions as $fileVersion ){
			$msg.= date('Y-m-d H:i:s', $fileVersion['timestamp']).' '.$fileVersion['version'].' '.sprintf('%14d', $fileVersion['size']).PHP_EOL;
			$size+=  $fileVersion['size'];
		}
		$msg.= '------------------------------------------------------------|--------------'.PHP_EOL;
		$msg.= sprintf('versions: %-4d ', count($versions)).'                                              '.sprintf('%14d', $size).PHP_EOL;
		return $msg;
	}

	/**
	 * @param Ric_Client_Client $client
	 * @param Ric_Client_Cli $cli
	 * @return string
	 * @throws RuntimeException
	 */
	static protected function commandRestore($client, $cli){
		$targetFileName = $cli->arguments[0];
		if( count($cli->arguments)==1 ){
			$resource = getcwd().'/'.basename($targetFileName);
		}else{
			$resource = $cli->arguments[1];
		}
		$targetFileName = $cli->getOption('prefix','').$targetFileName;
		$client->restore($targetFileName, $resource, $cli->getOption('pass'), $cli->getOption('version'), (true AND $cli->getOption('overwrite')));
		return 'OK';
	}

	/**
	 * @param Ric_Client_Client $client
	 * @param Ric_Client_Cli $cli
	 * @return string
	 * @throws RuntimeException
	 */
	static protected function commandDelete($client, $cli){
		$targetFileName = $cli->getArgument(1);
		$version = $cli->getArgument(2);
		if( $targetFileName===null OR $version===null ){
			throw new RuntimeException('name and version needed, use "all" for all version');
		}
		$targetFileName = $cli->getOption('prefix','').$targetFileName;
		return $client->delete($targetFileName, $version);
	}

	/**
	 * @param Ric_Client_Client $client
	 * @param Ric_Client_Cli $cli
	 * @return string
	 * @throws RuntimeException
	 */
	static protected function commandAdmin($client, $cli){
		if( count($cli->arguments)==0 ){
			throw new RuntimeException('admin command expected'.PHP_EOL.$client->getHelp('admin'));
		}
		$adminCommand = $cli->arguments[0];
		if( $adminCommand=='info' ){
			$msg = json_encode($client->info(), JSON_PRETTY_PRINT);
		}elseif( $adminCommand=='list' ){
			$msg = join(PHP_EOL, $client->listFiles());
		}elseif( $adminCommand=='listDetails' ){
			$msg = print_r($client->listFileDetails(), true);
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
		}elseif( $adminCommand=='removeFromCluster' ){
			$msg = $client->removeFromCluster($cli->arguments[1]);
		}else{
			throw new RuntimeException('unknown admin command');
		}
		return $msg;
	}

	/**
	 * @param $command
	 * @param $cli
	 */
	protected static function dumpParameters($command, $cli){
		echo 'command: ' . $command . PHP_EOL;
		echo 'arguments: ';
		foreach( $cli->arguments as $value ){
			echo '"' . $value . '", ';
		}
		echo PHP_EOL;
		echo 'options: ';
		foreach( $cli->options as $key => $value ){
			echo $key . ': "' . $value . '", ';
		}
		echo PHP_EOL;
		echo 'environment: ';
		foreach( $cli->env as $key => $value ){
			echo $key . ': "' . $value . '", ';
		}
		echo PHP_EOL;
	}

}
