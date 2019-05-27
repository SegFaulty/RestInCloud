<?php

/**
 * encryption
 * http://www.shellhacks.com/en/Encrypt-And-Decrypt-Files-With-A-Password-Using-OpenSSL
 * openssl enc -aes-256-cbc -salt -in file.txt -out file.txt.enc -k PASS
 * openssl enc -aes-256-cbc -d -in file.txt.enc -out file.txt -k PASS
 *
 * Class Ric_Client_Client
 */
class Ric_Client_Client {

	const MIN_SERVER_VERSION = '0.8.0'; // server needs to be on this or a higher version, BUT on the same MAJOR version  ok: 1.4.0 < 1.8.3  but fail:  1.4.0 < 2.3.0  because client is to old
	const CLIENT_VERSION = '0.5.0'; //

	const MAGIC_DELETION_TIMESTAMP = 1422222222; // 2015-01-25 22:43:42

	protected $server = '';
	protected $auth = '';
	protected $log = '';
	protected $debug = false;
	protected $checkVersion = true;

	/**
	 * @param string $serverHostPort
	 * @param $auth
	 */
	public function __construct($serverHostPort, $auth){
		$this->server = $serverHostPort;
		$this->logDebug('server:'.$serverHostPort);
		$this->auth = $auth;
	}

	/**
	 * @return string
	 */
	public function getLog(){
		return $this->log;
	}

	/**
	 * @param $bool
	 */
	public function setDebug($bool){
		$this->debug = (true AND $bool);
	}

	/**
	 * @param string $msg
	 */
	protected function log($msg){
		$this->log .= $msg.PHP_EOL;
	}

	/**
	 * enable/disable versionCheck  check client vs server version
	 * (if true, every request to a ric-server will contains a minVersion=MIN_SERVER_VERSION parameter  and will fail if they not match)
	 * returns old value
	 *
	 * @param bool $bool
	 * @return bool
	 */
	public function setCheckVersion($bool){
		$old = $this->checkVersion;
		$this->checkVersion = $bool;
		return $old;
	}

	/**
	 * @param string $msg
	 */
	protected function logDebug($msg){
		if( $this->debug ){
			fwrite(STDOUT, $msg.PHP_EOL);
		}
	}

	/**
	 * build api url
	 * @param string $fileName
	 * @param string $command
	 * @param array $parameters
	 * @throws RuntimeException
	 * @return string
	 */
	protected function buildUrl($fileName, $command = '', $parameters = []){
		if( $this->server=='' ){
			throw new RuntimeException('no server given');
		}
		$url = 'http://'.$this->server.'/';
		$url .= $fileName;
		if( $this->auth!='' ){
			$parameters += ['token' => $this->auth];  // add token
		}
		if( $this->checkVersion ){
			$parameters += ['minServerVersion' => self::MIN_SERVER_VERSION];
		}
		$url .= '?'.$command;
		if( !empty($parameters) ){
			$url .= '&'.http_build_query($parameters);
		}
		$this->logDebug(__METHOD__.' url:'.$url);
		return $url;
	}

	/**
	 * @param string $response
	 * @param array $headers
	 * @param string $responseFilePath
	 * @throws RuntimeException
	 */
	protected function checkServerResponse($response, $headers, $responseFilePath = ''){
		if( !isset($headers['Http-Code']) ){
			throw new RuntimeException('no api response code');
		}
		if( $headers['Http-Code']>=400 ){
			$msg = 'Failed: with code: '.$headers['Http-Code'];
			if( $response=='' AND $responseFilePath!='' AND file_exists($responseFilePath) AND filesize($responseFilePath)<100000 ){
				$response = file_get_contents($responseFilePath);
			}
			$result = json_decode($response, true);
			if( !empty($result['error']) ){
				$msg .= ' Error: '.$result['error'];
			}else{
				$msg .= $response;
			}
			throw new RuntimeException($msg);
		}
	}

	/**
	 * check result (array) or response (json) for 'status'=>'OK'
	 *
	 * @param $responseOrResult
	 * @return bool
	 */
	protected function isResponseStatusOk($responseOrResult){
		if( !is_array($responseOrResult) ){
			$responseOrResult = json_decode($responseOrResult, true);
		}
		return H::getIKS($responseOrResult, 'status')==='OK';
	}

	/**
	 * @param string $filePath
	 * @param string $name
	 * @param int $timestamp to set correct modificationTime [default:requestTime]
	 * @param string $retention to select the backup retention strategy [default:last3]
	 * @param bool $noSync to suppress synchronisation to replication servers (used for internal sync)
	 * @return string
	 * @throws RuntimeException
	 */
	public function storeFile($filePath, $name = '', $retention = null, $timestamp = null, $noSync = false){
		$params = [];
		if( $timestamp!==null ){
			$params['timestamp'] = $timestamp;
		}
		if( $retention!==null ){
			$params['retention'] = $retention;
		}
		if( $noSync ){
			$params['noSync'] = 1;
		}
		$params['sha1'] = sha1_file($filePath);
		$response = Ric_Rest_Client::putFile($this->buildUrl($name, '', $params), $filePath, $headers);
		$this->checkServerResponse($response, $headers);
		return $response;
	}

	/**
	 * default timestamp is "file" : file modification time
	 *
	 * @param string $resource
	 * @param string $targetFileName
	 * @param string $password
	 * @param string $retention
	 * @param int|string $timestamp
	 * @param int $minReplicas
	 * @param int $minSize
	 * @throws RuntimeException
	 * @return bool
	 */
	public function backup($filePath, $targetFileName, $password = null, $retention = null, $timestamp = 'file', $minReplicas = null, $minSize = 1){
		if( is_file($filePath) ){
			// fine
		}elseif( is_dir($filePath) ){
			throw new RuntimeException('backup of directories is not supported, use "dumper" to build a file and feed this here');
		}elseif( $filePath=='STDIN' ){
			$MAX_STDIN_LEN = 1000000;
			$filePath = $this->getTmpFilePath();
			while( !feof(STDIN) ){
				$data = fread(STDIN, $MAX_STDIN_LEN);
				file_put_contents($filePath, $data, FILE_APPEND);
			}
			if( filesize($filePath)==0 ){
				throw new RuntimeException('STDIN was empty, i stop here');
			}
			if( filesize($filePath)==$MAX_STDIN_LEN ){
				throw new RuntimeException('STDIN size limit exeeded ['.$MAX_STDIN_LEN.'] operation cancelled, pipe to a standard file and feed this here ');
			}
		}else{
			throw new RuntimeException('resource not found or unsupported type');
		}

		if( $timestamp=='file' ){
			$timestamp = filemtime($filePath);
		}
		if( $timestamp=='now' OR $timestamp<=0 ){
			$timestamp = time();
		}

		if( filesize($filePath)<$minSize ){
			throw new RuntimeException('required min file size('.$minSize.') not reached (was '.filesize($filePath).')');
		}

		if( $password!==null ){ // since 0.2 we only encrypt if password not null
			$filePath = $this->getEncryptedFilePath($filePath, $password, substr(md5($targetFileName), 0, 8)); // we have to provide the same salt for the same filename to get a file with the same sha1, but it should be not the same for all files, so we take the $targetFileName itself ;-)
		}

		$sha1 = sha1_file($filePath);
		$params = [];
		$params['sha1'] = $sha1;
		$params['timestamp'] = $timestamp;
		$fileUrl = $this->buildUrl($targetFileName, '', $params);
		// Post
		$this->logDebug('POST refresh to: '.$fileUrl.' with timestamp: '.$timestamp.'('.date('Y-m-d H:i:s', $timestamp).')');
		$headers = [];
		$response = Ric_Rest_Client::post($fileUrl, [], $headers);
		$this->checkServerResponse($response, $headers);
		if( !$this->isResponseStatusOk($response) ){
			// Put
			$this->logDebug('POST refresh failed, file has to be sent via PUT');
			if( $retention ){
				$params['retention'] = $retention;
			}
			$fileUrl = $this->buildUrl($targetFileName, '', $params);
			$headers = [];
			$response = Ric_Rest_Client::putFile($fileUrl, $filePath, $headers);
			$this->checkServerResponse($response, $headers);
			$this->logDebug('PUT result:'.$response);
		}else{
			$this->logDebug('POST refresh succeeded, no file transfer necessary');
		}
		// Verify
		$this->check($targetFileName, $minReplicas, $sha1);
		return true;
	}

	/**
	 * @param $targetFileName
	 * @param int $minReplicas
	 * @param string $sha1
	 * @param int $minSize
	 * @param int $minTimestamp
	 * @throws RuntimeException
	 * @return bool
	 */
	public function check($targetFileName, $minReplicas = null, $sha1 = null, $minSize = null, $minTimestamp = null){
		$params = [];
		if( $minReplicas!==null ){
			$params['minReplicas'] = $minReplicas;
		}
		if( $sha1!==null ){
			$params['sha1'] = $sha1;
		}
		if( $minSize!==null ){
			$params['minSize'] = $minSize;
		}
		if( $minTimestamp!==null ){
			$params['minTimestamp'] = $minTimestamp;
		}
		$fileUrl = $this->buildUrl($targetFileName, 'check', $params);
		$response = Ric_Rest_Client::get($fileUrl, [], $headers);
		$this->checkServerResponse($response, $headers);
		$this->logDebug('Check ('.$fileUrl.') result: '.$response);
		if( !$this->isResponseStatusOk($response) ){
			throw new RuntimeException('check failed: '.$response);
		}
		return true;
	}

	/**
	 *
	 * @param string $backupFileName
	 * @param string $targetFilePath
	 * @param null $password
	 * @param string $version
	 * @param bool $overwrite
	 * @return bool
	 */
	public function restore($backupFileName, $targetFilePath, $password = null, $version = null, $overwrite = true){
		$params = [];
		if( $version ){
			$params['version'] = $version;
		}
		$tmpFilePath = $this->getTmpFilePath();
		$fileUrl = $this->buildUrl($backupFileName, '', $params);
		// get
		$oFH = fopen($tmpFilePath, 'wb+');
		$this->logDebug('get: '.$fileUrl);
		Ric_Rest_Client::get($fileUrl, [], $headers, $oFH);
		$this->checkServerResponse('', $headers, $tmpFilePath);

		$this->logDebug('downloaded as tmpFile: '.$tmpFilePath.'['.filesize($tmpFilePath).']');
		if( $password!==null ){
			$tmpFilePath = $this->getDecryptedFilePath($tmpFilePath, $password);
		}

		if( file_exists($targetFilePath) AND !$overwrite ){
			throw new RuntimeException('resource ['.realpath($targetFilePath).'] (file or dir) already exists! restore skipped');
		}
		$this->logDebug('restore as file: '.$targetFilePath);
		if( !copy($tmpFilePath, $targetFilePath) ){
			throw new RuntimeException('restore as file: '.$targetFilePath.' failed!');
		}


		return true;
	}

	/**
	 * encrypt to a new file
	 * if the passowrd is empty, the result file is still encoded with a salt !!!
	 * @param string $filePath
	 * @param string $password
	 * @param string $salt
	 * @throws RuntimeException
	 * @return string
	 */
	protected function getEncryptedFilePath($filePath, $password, $salt = '_sdffHGetdsga'){
		if( !is_file($filePath) ){
			throw new RuntimeException('file not found or not a regular file: '.$filePath);
		}

		$encryptedFilePath = $this->getTmpFilePath('.ricenc');

		$command = 'openssl enc -aes-256-cbc -S '.bin2hex(substr($salt, 0, 8)).' -in '.$filePath.' -out '.$encryptedFilePath.' -k '.escapeshellarg((string) $password);
		exec($command, $output, $status);
		if( $status!=0 ){
			throw new RuntimeException('encryption failed: '.$command.' with: '.print_r($output, true), 500);
		}

		return $encryptedFilePath;
	}

	/**
	 * encrypt to a new file
	 * if the passowrd is empty, the result file is still encoded with a salt !!!
	 * @param string $encryptedFilePath
	 * @param string $password
	 * @return string
	 * @throws RuntimeException
	 */
	protected function getDecryptedFilePath($encryptedFilePath, $password){
		if( !is_file($encryptedFilePath) ){
			throw new RuntimeException('file not found or not a regular file: '.$encryptedFilePath);
		}
		$decryptedFilePath = $this->getTmpFilePath();
		$command = 'openssl enc -aes-256-cbc -d -in '.$encryptedFilePath.' -out '.$decryptedFilePath.' -k '.escapeshellarg((string) $password);
		exec($command, $output, $status);
		if( $status!=0 ){
			throw new RuntimeException('decryption failed '.$command.' with '.print_r($output, true), 500);
		}

		return $decryptedFilePath;
	}

	/**
	 * delete on backupCluster
	 * @param string $targetFileName
	 * @param string|null $version
	 * @return string
	 */
	public function delete($targetFileName, $version = null){
		$params = [];
		if( $version!==null AND $version!='all' ){
			$params['version'] = $version;
		}
		$response = Ric_Rest_Client::delete($this->buildUrl($targetFileName, 'delete', $params), [], $headers);
		$this->checkServerResponse($response, $headers);
		return $response;
	}

	/**
	 * get version list of a file
	 * @param $targetFileName
	 * @return array
	 */
	public function versions($targetFileName){
		$response = Ric_Rest_Client::get($this->buildUrl($targetFileName, 'list'), [], $headers);
		$this->checkServerResponse($response, $headers);
		return json_decode($response, true);
	}

	/**
	 * get server info
	 * if admin, you get more info
	 * @return array
	 */
	public function info(){
		$oldCheckVersion = $this->setCheckVersion(false);
		$response = Ric_Rest_Client::get($this->buildUrl('', 'info', []), [], $headers);
		$this->setCheckVersion($oldCheckVersion);
		$this->checkServerResponse($response, $headers);
		return json_decode($response, true);
	}

	/**
	 * list files
	 * @param string $pattern
	 * @return array
	 */
	public function listFiles($pattern=''){
		$parameters = [];
		if( !empty($pattern) ){
			$parameters['pattern'] = $pattern;
		}
		$response = Ric_Rest_Client::get($this->buildUrl('', 'list'), $parameters, $headers);
		$this->checkServerResponse($response, $headers);
		return json_decode($response, true);
	}

	/**
	 * check cluster health
	 * @throws RuntimeException
	 * @return string
	 */
	public function health(&$cliStatus, &$stderrMsg){
		$response = Ric_Rest_Client::get($this->buildUrl('', 'health'), [], $headers);
		$this->checkServerResponse($response, $headers);
		if( !$this->isResponseStatusOk($response) ){
			$cliStatus = false;
			$stderrMsg = 'health check critical: '.$response;
		}
		return $response;
	}

	/**
	 * @param $serverHostPort
	 * @return string
	 */
	public function addServer($serverHostPort){
		$response = Ric_Rest_Client::post($this->buildUrl('', '', ['action' => 'addServer', 'addServer' => $serverHostPort]), [], $headers);
		$this->checkServerResponse($response, $headers);
		return $response;
	}

	/**
	 * @param $serverHostPort
	 * @return string
	 */
	public function removeServer($serverHostPort){
		$response = Ric_Rest_Client::post($this->buildUrl('', '', ['action' => 'removeServer', 'removeServer' => $serverHostPort]), [], $headers);
		$this->checkServerResponse($response, $headers);
		return $response;
	}

	/**
	 * @param $serverHostPort
	 * @return string
	 */
	public function joinCluster($serverHostPort){
		$response = Ric_Rest_Client::post($this->buildUrl('', '', ['action' => 'joinCluster', 'joinCluster' => $serverHostPort]), [], $headers);
		$this->checkServerResponse($response, $headers);
		return $response;
	}

	/**
	 * @return string
	 */
	public function leaveCluster(){
		$response = Ric_Rest_Client::post($this->buildUrl('', '', ['action' => 'leaveCluster']), [], $headers);
		$this->checkServerResponse($response, $headers);
		return $response;
	}

	/**
	 * @param string $serverHostPort
	 * @return string
	 */
	public function removeFromCluster($serverHostPort){
		$response = Ric_Rest_Client::post($this->buildUrl('', '', ['action' => 'removeFromCluster', 'removeFromCluster' => $serverHostPort]), [], $headers);
		$this->checkServerResponse($response, $headers);
		return $response;
	}

	/**
	 * @param string $targetServerHostPort
	 * @return array
	 */
	public function copyServer($targetServerHostPort){
		$filesCopied = 0;
		$fileNames = $this->listFiles();
		foreach( $fileNames as $fileName ){
			$versionInfos = $this->versions($fileName);
			foreach( $versionInfos as $versionInfo ){
				$version = $versionInfo['version'];
				$url = $this->buildUrl($fileName, '', ['action' => 'push', 'server' => $targetServerHostPort, 'version' => $version]);
				$headers = [];
				$response = Ric_Rest_Client::post($url, [], $headers);
				$this->checkServerResponse($response, $headers);
				$filesCopied++;
			}
		}
		return ['status' => 'OK', 'filesCopied' => $filesCopied];
	}

	/**
	 * @param string $pattern
	 * @param bool $cliStatus
	 * @param string $stderrMsg
	 * @return string
	 */
	public function checkConsistency($pattern, &$cliStatus, &$stderrMsg){
		$response = '';
		$info = $this->info();
		$status = 'OK';
		$servers = H::getIKS($info, ['config','servers'], []);
		array_unshift($servers, $info['config']['hostPort']); // add current server
		if( count($servers)>1 ){
			// check all files from all servers
			$allKnownFiles = [];
			$knownFilesPerServer = [];
			$clients = []; /** @var Ric_Client_Client[] $clients */
			foreach( $servers as $server ){
				$clients[$server] = new Ric_Client_Client($server, $info['config']['adminToken']);
				$files = $clients[$server]->listFiles($pattern);
				$knownFilesPerServer[$server] = $files;
				$allKnownFiles = array_unique(array_merge($allKnownFiles, $files));
			}
			$missingFilesPerServer = [];
			foreach( $knownFilesPerServer as $server => $files ){
				$serverMissingFiles = array_diff($allKnownFiles, $files);
				if( $serverMissingFiles ){
					$missingFilesPerServer[$server] = $serverMissingFiles;
					$stderrMsg .= '[CRITICAL] '.count($serverMissingFiles).' files missing on server: '.$server.PHP_EOL.'  '.join(PHP_EOL.'  ', $serverMissingFiles).PHP_EOL;
					$status = 'CRITICAL';
				}
			}
			if( empty($missingFilesPerServer) ){
				$response .= '[OK] '.count($allKnownFiles).' files existing on all servers'.PHP_EOL;
			}

			// check versions
			foreach( $allKnownFiles as $fileName ){
				$allKnownVersions = [];
				$knownVersionsPerServer = [];
				$primaryFileVersion = null;
				$primaryFileTimestamp = null;
				foreach( $servers as $server ){
					$versionInfos = $clients[$server]->versions($fileName);
					$versions = [];
					foreach( $versionInfos as $versionInfo ){
						if( $primaryFileVersion OR $versionInfo['timestamp']>$primaryFileTimestamp ){
							$primaryFileVersion = $versionInfo['version'];
							$primaryFileTimestamp = $versionInfo['timestamp'];
						}
						$versions[] = $versionInfo['version'];
					}
					$knownVersionsPerServer[$server] = $versions;
					$allKnownVersions = array_unique(array_merge($allKnownVersions, $versions));
				}
				$missingVersionsPerServer = [];
				foreach( $knownVersionsPerServer as $server => $versions ){
					$serverMissingVersions = array_diff($allKnownVersions, $versions);
					if( $serverMissingVersions ){
						if( !in_array($primaryFileVersion, $versions) ){
							$status = 'CRITICAL';
							$stderrMsg .= '[CRITICAL] primary version ['.$primaryFileVersion.'] from '.date('Y-m-d H:i:s', $primaryFileTimestamp).' missing of file: '.$fileName.' on server: '.$server.PHP_EOL;
						}
						if( $status=='OK' ){
							$status = 'WARNING';
						}
						$stderrMsg .= '[WARNING] '.count($serverMissingVersions).' versions missing of file: '.$fileName.' on server: '.$server.PHP_EOL.'  '.join(PHP_EOL.'  ', $serverMissingVersions).PHP_EOL;
						$missingVersionsPerServer[$server] = $serverMissingVersions;
					}
				}
				if( empty($missingVersionsPerServer) ){
					$response .= '[OK] '.count($allKnownVersions).' versions for '.$fileName.' existing on all servers'.PHP_EOL;
				}

			}
			$response .= 'Status: '.$status.PHP_EOL;
#			$response .= print_r($knownFilesPerServer, true);
		}else{
			$response .= 'no others servers, no consistence check necessary'.PHP_EOL;
		}
		$cliStatus = ($status=='OK');
		return $response;
	}

	/**
	 * Todo do this on server??!?
	 * @param string $pattern regex
	 * @return array
	 */
	public function getInventory($pattern){
		$files = $this->listFiles($pattern);
		foreach( $files as $fileName ){
			$versions = $this->versions($fileName);
			$lastVersion = reset($versions);
			$allVersionsSize = 0;
			$versionCount = 0;
			foreach( $versions as $fileVersion ){
				$allVersionsSize += $fileVersion['size'];
				if( $fileVersion['timestamp']!=self::MAGIC_DELETION_TIMESTAMP ){ # '-- D E L E T E D --';
					$versionCount++;
				}
			}
			$result[$fileName] = [
					'file'     => $fileName,
					'time'     => $lastVersion['timestamp'],
					'size'     => $lastVersion['size'],
					'version'  => $lastVersion['version'],
					'versions' => $versionCount,
					'allsize'  => $allVersionsSize,
			];
		}
		return $result;
	}

	/**
	 * @param string $pattern
	 * @param string $localSnapshotDir
	 * @return array
	 */
	public function takeSnapshot($pattern, $localSnapshotDir){
		$targetDir = rtrim($localSnapshotDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR; // ensure we have a trailing /
		if( !is_dir($targetDir) ){
			throw new RuntimeException($targetDir.' is not a writable targetDir');
		}
		$inventory = $this->getInventory($pattern);

		$this->logDebug('start snapshot of '.count($inventory).' server files'.($pattern ? ' with pattern "'.$pattern.'"' : ''));
		$transferredFiles = 0;
		$transferredBytes = 0;
		$fileNumber = 0;
		$fileCount = count($inventory);
		$indent = str_repeat(' ', strlen($fileCount) * 2 + 2);
		foreach( $inventory as $fileEntry ){
			$fileNumber++;
			$this->logDebug(str_pad($fileNumber, strlen($fileCount), 0, STR_PAD_LEFT).'/'.$fileCount.' '.$fileName.' '.filesize($localFile).'Byte '.date('Y-m-d H:i:s', filemtime($localFile)));

			$fileName = $fileEntry['file'];
			$localFile = $targetDir.$fileName;
			if( file_exists($localFile) AND filesize($localFile)==$fileEntry['size'] AND sha1_file($localFile)==$fileEntry['version'] ){ // check if file already exists and is uptodate
				touch($localFile, $fileEntry['time']); // is in sync, only update modification date
				$this->logDebug($indent.' is already uptodate');
			}else{
				$this->logDebug(' update to new version ('.date('Y-m-d H:is:s', $fileEntry['time']).') - '.$fileEntry['size'].'Byte');
				$this->restore($fileName, $localFile); // overwrite active
				if( file_exists($localFile) AND filesize($localFile)==$fileEntry['size'] AND sha1_file($localFile)==$fileEntry['version'] ){ // check again
					// fine
					touch($localFile, $fileEntry['time']); // is in sync, only update modification date
					$transferredFiles++;
					$transferredBytes += $fileEntry['size'];
					$this->logDebug($indent.' updated successfully to version '.$fileEntry['version']);
				}else{
					throw new RuntimeException('sanity check after restore file '.$fileName.' to '.$localFile.' failed! WHoops!');
				}
			}
		}

		$result = ['status' => 'OK', 'serverFiles' => $fileCount, 'transferredFiles' => $transferredFiles, 'transferredBytes' => $transferredBytes];
		$this->logDebug('end snapshot '.H::implodeKeyValue($result));

		return $result;
	}



	##### TMP File handling ########
	protected $tmpFilePaths = [];
	protected $tmpFileDir = ''; // use system default

	/**
	 * set the tmpDir to writable and secure location
	 * @param $dir
	 */
	public function setTmpDir($dir){
		$this->tmpFileDir = $dir;
	}

	/**
	 * return filePath
	 * file will be delete on script termination (via register_shutdown_function deleteTmpFiles)
	 * extension ".jpg" for imagemagick zum beispiel
	 * use __CLASS__
	 * if self::$tmpFileDir is empty the system default tmp dir is used
	 * @param string $extension
	 * @return string
	 */
	public function getTmpFilePath($extension = ''){
		$tmpFile = $this->tmpFileDir;
		if( $tmpFile=='' ){
			$tmpFile = sys_get_temp_dir();
		}
		$tmpFile .= '/_'.__CLASS__.'_'.uniqid('', true).$extension;
		$this->tmpFilePaths[] = $tmpFile;
		return $tmpFile;
	}

	/**
	 * remove all tmpFiles
	 */
	public function __destruct(){
		foreach( $this->tmpFilePaths as $tmpFilePath ){
			if( file_exists($tmpFilePath) ){
				unlink($tmpFilePath);
			}
		}
	}
}
