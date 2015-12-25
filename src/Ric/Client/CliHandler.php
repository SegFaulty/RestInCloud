<?php

/**
 * todo check for to many arguments, helperFunction ?
 * Class Ric_Client_CliHandler
 */
class Ric_Client_CliHandler {

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
		$command = $cli->getArgument(1);
		if( $cli->getOption('verbose') ){
			self::dumpParameters($cli);
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
					$msg = self::getHelp($cli->getArgument(1), $helpString);
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
		$resource = $cli->getArgument(2);
		if( $cli->getArgumentCount(2, 3)==2 ){
			if( is_file($resource) ){
				$targetFileName = basename($resource);
			}elseif( is_dir($resource) ){
				$targetFileName = basename($resource).'.tar.bz2';
			}else{
				throw new RuntimeException('no targetFileName given, but resource is not a regular file or dir!');
			}
		}else{
			$targetFileName = $cli->getArgument(3);
		}
		$targetFileName = $cli->getOption('prefix', '').$targetFileName;
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
		$cli->getArgumentCount(2, 2);
		$targetFileName = $cli->getArgument(2);
		$targetFileName = $cli->getOption('prefix', '').$targetFileName;
		$minTimestamp = $cli->getOption('minTimestamp');
		if( $minTimestamp<0 ){
			$minTimestamp = time() + intval($minTimestamp); // add because $minTimestamp is negative
		}
		$client->check($targetFileName, $cli->getOption('minReplicas'), $cli->getOption('sha1'), $cli->getOption('minSize'), $minTimestamp);
		return 'OK';
	}

	/**
	 * @param Ric_Client_Client $client
	 * @param Ric_Client_Cli $cli
	 * @return string
	 * @throws RuntimeException
	 */
	static protected function commandList($client, $cli){
		$cli->getArgumentCount(2, 2); // check argumentCount
		$msg = '';
		$targetFileName = $cli->getArgument(2);
		$targetFileName = $cli->getOption('prefix', '').$targetFileName;
		$versions = $client->versions($targetFileName);
		if( count($versions)>0 ){
			$msg .= $targetFileName.PHP_EOL;
			$msg .= 'Date       Time     Version (sha1)                           Size'.PHP_EOL;
			$msg .= '----------|--------|----------------------------------------|--------------'.PHP_EOL;
			$size = 0;
			foreach( $versions as $fileVersion ){
				$date = date('Y-m-d H:i:s', $fileVersion['timestamp']);
				if( $fileVersion['timestamp']==1422222222 ){
					$date = '-- D E L E T E D --';
				}
				$msg .= $date.' '.$fileVersion['version'].' '.sprintf('%14d', $fileVersion['size']).PHP_EOL;
				$size += $fileVersion['size'];
			}
			$msg .= '------------------------------------------------------------|--------------'.PHP_EOL;
			$msg .= sprintf('versions: %-4d ', count($versions)).'                                              '.sprintf('%14d', $size).PHP_EOL;
		}else{
			$msg .= 'no version of "'.$targetFileName.'" found!'.PHP_EOL;
		}
		return $msg;
	}

	/**
	 * @param Ric_Client_Client $client
	 * @param Ric_Client_Cli $cli
	 * @return string
	 * @throws RuntimeException
	 */
	static protected function commandRestore($client, $cli){
		$targetFileName = $cli->getArgument(2);
		if( $cli->getArgumentCount(2, 3)==2 ){
			$resource = getcwd().'/'.basename($targetFileName);
		}else{
			$resource = $cli->getArgument(3);
		}
		$targetFileName = $cli->getOption('prefix', '').$targetFileName;
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
		$cli->getArgumentCount(3, 3); // check argumentCount
		$targetFileName = $cli->getArgument(2);
		$version = $cli->getArgument(3);
		if( $targetFileName===null OR $version===null ){
			throw new RuntimeException('name and version needed, use "all" for all version');
		}
		$targetFileName = $cli->getOption('prefix', '').$targetFileName;
		return $client->delete($targetFileName, $version);
	}

	/**
	 * @param Ric_Client_Client $client
	 * @param Ric_Client_Cli $cli
	 * @return string
	 * @throws RuntimeException
	 */
	static protected function commandAdmin($client, $cli){
		if( $cli->getArgumentCount()==1 ){
			throw new RuntimeException('admin command expected, see help');
		}
		$adminCommand = $cli->getArgument(2);
		if( $adminCommand=='info' ){
			$msg = json_encode($client->info(), JSON_PRETTY_PRINT);
		}elseif( $adminCommand=='list' ){
			$msg = join(PHP_EOL, $client->listFiles());
		}elseif( $adminCommand=='health' ){
			$msg = $client->health();
		}elseif( $adminCommand=='addServer' ){
			if( $cli->getArgumentCount()!=3 ){
				throw new RuntimeException('needs one arg (targetServer)');
			}
			$msg = $client->addServer($cli->getArgument(3));
		}elseif( $adminCommand=='removeServer' ){
			if( $cli->getArgumentCount()!=3 ){
				throw new RuntimeException('needs one arg (targetServer)');
			}
			$msg = $client->removeServer($cli->getArgument(3));
		}elseif( $adminCommand=='joinCluster' ){
			if( $cli->getArgumentCount()!=3 ){
				throw new RuntimeException('needs one arg (clusterServer)');
			}
			$msg = $client->joinCluster($cli->getArgument(3));
		}elseif( $adminCommand=='leaveCluster' ){
			$msg = $client->leaveCluster();
		}elseif( $adminCommand=='removeFromCluster' ){
			if( $cli->getArgumentCount()!=3 ){
				throw new RuntimeException('needs one arg (targetServer)');
			}
			$msg = $client->removeFromCluster($cli->getArgument(3));
		}elseif( $adminCommand=='copyServer' ){
			if( $cli->getArgumentCount()!=3 ){
				throw new RuntimeException('needs one arg (targetServer)');
			}
			$msg = $client->copyServer($cli->getArgument(3));
		}else{
			throw new RuntimeException('unknown admin command');
		}
		return $msg;
	}

	/**
	 * @param Ric_Client_Cli $cli
	 */
	protected static function dumpParameters($cli){
		echo $cli->dumpParameters();
	}

}
