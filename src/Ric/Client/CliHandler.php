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
		$stderrMsg = '';
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
			$client->setQuiet($cli->getOption('quiet'));
			$client->setTmpDir($cli->getOption('tempDir'));
			$client->setCheckVersion(!$cli->getOption('ignoreVersion'));
			Ric_Rest_Client::setDefaultCurlOption(CURLOPT_TIMEOUT, $cli->getOption('timeout')); // set or remove (if not given) timeout
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
					$msg = self::commandAdmin($client, $cli, $status, $stderrMsg);
					break;
				case 'help':
					$msg = self::getHelp($helpString, $cli->getArgument(2, 'Summary'));
					break;
				default:
					throw new RuntimeException('command expected'.PHP_EOL.self::getHelp($helpString));

			}
			if( $cli->getOption('verbose') ){
				echo $client->getLog();
			}
		}catch(Exception $e){
			$status = 1;
			$stderrMsg = 'Exception: '.$e->getMessage();
#			ini_set('display_errors', 'stderr'); // ensure we write to STDERR
#			fwrite(STDERR, trim('ERROR: '.$e->getMessage()).PHP_EOL);
#			file_put_contents("php://stderr", rtrim($e->getMessage()).PHP_EOL);
		}

		if( !$cli->getOption('quite') ){
			echo rtrim($msg).PHP_EOL; // add trailing newline
		}
		if( is_bool($status) ){
			$status = (int) !$status;
		}
		if( $stderrMsg ){
			ini_set('display_errors', 'stderr'); // ensure we write to STDERR
			fwrite(STDERR, trim($stderrMsg).PHP_EOL);
		}
		return $status; // success -> 0 , failed >= 1
	}

	/**
	 * returns null (!) if no path is given
	 * @param string $filePath
	 * @throws RuntimeException
	 * @return string
	 */
	static protected function resolveSecretFile($filePath){
		$secret = null;
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
	static protected function getHelp($helpString, $command = 'Summary'){
		if( $command and preg_match('~\n(## Help '.preg_quote($command, '~').'(.*?))(\n## |$)~s', $helpString, $matches) ){
			$helpString = $matches[1];
		}
		$helpString = 'ric client v'.Ric_Client_Client::CLIENT_VERSION.' required server version: '.Ric_Client_Client::MIN_SERVER_VERSION.PHP_EOL.PHP_EOL.$helpString;
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
			}else{
				throw new RuntimeException('no backupFileName given, but source is not a regular file! please add an backupFileName as parameter');
			}
		}else{
			$targetFileName = $cli->getArgument(3);
		}
		$targetFileName = $cli->getOption('prefix', '').$targetFileName;
		$password = $cli->getOption('pass', self::resolveSecretFile($cli->getOption('passFile')));
		$client->backup($resource, $targetFileName, $password, $cli->getOption('retention'), $cli->getOption('timestamp', 'file'), $cli->getOption('minReplicas'), $cli->getOption('minSize'));
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
			$msg .= '----------|--------|----------------------------------------|--------------|----------'.PHP_EOL;
			$size = 0;
			foreach( $versions as $fileVersion ){
				$date = date('Y-m-d H:i:s', $fileVersion['timestamp']);
				if( $fileVersion['timestamp']==1422222222 ){
					$date = '-- D E L E T E D --';
				}
				$msg .= $date.' '.$fileVersion['version'].' '.sprintf('%14d', $fileVersion['size']).' '.sprintf('%10s', self::convertMemory($fileVersion['size'])).PHP_EOL;
				$size += $fileVersion['size'];
			}
			$msg .= '------------------------------------------------------------|--------------|----------'.PHP_EOL;
			$msg .= sprintf('versions: %-4d ', count($versions)).'                                              '.sprintf('%14d', $size).' '.sprintf('%10s', self::convertMemory($size)).PHP_EOL;
		}else{
			$msg .= 'no version of "'.$targetFileName.'" found!'.PHP_EOL;
		}
		return $msg;
	}

	/**
	 * inspired https://gist. github. com/mehdichaouch/341a151dd5f469002a021c9396aa2615
	 * @param $byte
	 * @return string
	 */
	static protected function convertMemory($byte){
		$unit = array('B', 'kB', 'MB', 'GB', 'TB', 'PB');
		return round($byte / pow(1000, ($i = floor(log($byte, 1000)))), 2).' '.$unit[$i];
	}

	/**
	 * @param Ric_Client_Client $client
	 * @param Ric_Client_Cli $cli
	 * @return string
	 * @throws RuntimeException
	 */
	static protected function commandRestore($client, $cli){
		$backupFileName = $cli->getArgument(2);
		if( $cli->getArgumentCount(2, 3)==2 ){
			$resource = getcwd().'/'.basename($backupFileName);
		}else{
			$resource = $cli->getArgument(3);
		}
		$backupFileName = $cli->getOption('prefix', '').$backupFileName;
		$password = $cli->getOption('pass', self::resolveSecretFile($cli->getOption('passFile')));
		$client->restore($backupFileName, $resource, $password, $cli->getOption('version'), (true AND $cli->getOption('overwrite')));
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
	 * @param bool $status
	 * @param string $stderrMsg
	 * @return string
	 */
	static protected function commandAdmin($client, $cli, &$status, &$stderrMsg){
		if( $cli->getArgumentCount()==1 ){
			throw new RuntimeException('admin command expected, see help');
		}
		$adminCommand = $cli->getArgument(2);
		if( $adminCommand=='info' ){
			$msg = json_encode($client->info(), JSON_PRETTY_PRINT);
		}elseif( $adminCommand=='list' ){
			$pattern = self::checkPattern($cli->getArgument(3, ''));
			$msg = join(PHP_EOL, $client->listFiles($pattern));
		}elseif( $adminCommand=='inventory' ){
			$pattern = self::checkPattern($cli->getArgument(3, ''));
			$sort = $cli->getArgument(4, 'file');
			$msg = self::adminBuildInventory($client, $pattern, $sort);
		}elseif( $adminCommand=='health' ){
			$msg = $client->health($status, $stderrMsg);
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
			$msg = json_encode($client->copyServer($cli->getArgument(3)));
		}elseif( $adminCommand=='checkConsistency' ){
			$pattern = self::checkPattern($cli->getArgument(3, ''));
			$msg = $client->checkConsistency($pattern, $status, $stderrMsg);
		}elseif( $adminCommand=='healConsistency' ){
			$pattern = self::checkPattern($cli->getArgument(3, ''));
			$msg = $client->checkConsistency($pattern, $status, $stderrMsg, true);
		}elseif( $adminCommand=='snapshot' ){
			if( $cli->getArgumentCount()<3 ){
				throw new RuntimeException('needs one arg (targetDir)');
			}
			$localSnapshotDir = $cli->getArgument(3);
			$pattern = self::checkPattern($cli->getArgument(4, ''));
			$msg = json_encode($client->takeSnapshot($pattern, $localSnapshotDir));
		}else{
			throw new RuntimeException('unknown admin command');
		}
		return $msg;
	}

	/**
	 * wildcard pattern * ? to REGEX pattern
	 * @param string $pattern
	 * @return string
	 */
	static protected function checkPattern($pattern){
		if( $pattern and ctype_alnum(substr($pattern, 0, 1)) ){ // detect non REGEX pattern
			$pattern = "#".strtr(preg_quote($pattern, '#'), array('\*' => '.*', '\?' => '.'))."#i"; // and make it REGEX
		}
		return $pattern;
	}

	/**
	 * @param Ric_Client_Cli $cli
	 */
	static protected function dumpParameters($cli){
		echo $cli->dumpParameters();
	}

	/**
	 * @param Ric_Client_Client $client
	 * @param string $pattern
	 * @param string $sort
	 * @return string
	 */
	private static function adminBuildInventory($client, $pattern, $sort){
		$overallSize = 0;
		$overallCount = 0;
		$allLastVersionsSize = 0;
		$inventory = $client->getInventory($pattern);
		foreach( $inventory as $entry ){
			$allLastVersionsSize += $entry['size'];
			$overallSize += $entry['allsize'];
			$overallCount += $entry['versions'];
		}

		if( $inventory ){
			$firstEntry = current($inventory);
			if( !isset($firstEntry[$sort])){
				throw new RuntimeException('unknown sort parameter "'.$sort.'" valid paramaters: "'.implode('", "', array_keys(current($inventory))).'"');
			}
			// sort by $sort
			array_multisort(array_column($inventory, $sort), SORT_ASC, $inventory);
		}

		$msg = count($inventory).' Files sorted by '.$sort.PHP_EOL;
		$msg .= 'Date       Time               Size #Vers  all Vers Size  File'.PHP_EOL;
		$msg .= '----------|--------|--------------|-----|--------------|--------------'.PHP_EOL;
		foreach( $inventory as $fileResult ){
			$msg .= date('Y-m-d H:i:s', $fileResult['time']);
			$msg .= ' '.sprintf('%14s', number_format($fileResult['size'], 0, ',', '.')).' '.sprintf('%5d', $fileResult['versions']).' '.sprintf('%14.14s', number_format($fileResult['allsize'], 0, ',', '.'));
			$msg .= ' '.$fileResult['file'].PHP_EOL;
		}
		$msg .= '           overall ';
		$msg .= ' '.sprintf('%14s', number_format($allLastVersionsSize, 0, ',', '.')).' '.sprintf('%5d', $overallCount).' '.sprintf('%14.14s', number_format($overallSize, 0, ',', '.'));
		return $msg;
	}

}
