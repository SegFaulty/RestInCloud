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

	const MIN_SERVER_VERSION = '0.6.0'; // server needs to be on this or a higher version, BUT on the same MAJOR version  ok: 1.4.0 < 1.8.3  but fail:  1.4.0 < 2.3.0  because client is to old

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
			$this->log .= date('Y-m-d H:i:s').' '.$msg.PHP_EOL;
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
	 * @return array
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
		$response = Ric_Rest_Client::putFile($this->buildUrl($name, '', $params), $filePath, $headers);
		$this->checkServerResponse($response, $headers);
		return $response;
	}

	/**
	 *
	 * @param string $resource
	 * @param string $targetFileName
	 * @param string $password
	 * @param string $retention
	 * @param int $timestamp
	 * @param int $minReplicas
	 * @param int $minSize
	 * @throws RuntimeException
	 * @return bool
	 */
	public function backup($resource, $targetFileName, $password = null, $retention = null, $timestamp = null, $minReplicas = null, $minSize = 1){
		$rawFilePath = $this->getFilePathForResource($resource);

		if( $timestamp=='file' ){
			$timestamp = filemtime($rawFilePath);
		}
		if( $timestamp=='now' OR $timestamp<=0 ){
			$timestamp = time();
		}

		if( filesize($rawFilePath)<$minSize ){
			throw new RuntimeException('required min file size('.$minSize.') not reached (was '.filesize($rawFilePath).')');
		}

		$filePath = $this->getEncryptedFilePath($rawFilePath, $password, substr(md5($targetFileName), 0, 8)); // we have to provide the same salt for the same filename to get a file with the same sha1, but it should be not the same for all files, so we take the $targetFileName itself ;-)

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
	 * @param string $targetFileName
	 * @param string $resource
	 * @param null $password
	 * @param string $version
	 * @param bool $overwrite
	 * @return bool
	 */
	public function restore($targetFileName, $resource, $password = null, $version = null, $overwrite = true){
		$tmpFilePath = $this->getTmpFilePath();
		$params = [];
		if( $version ){
			$params['version'] = $version;
		}
		$fileUrl = $this->buildUrl($targetFileName, '', $params);
		// get
		$oFH = fopen($tmpFilePath, 'w+');
		$this->logDebug('get: '.$fileUrl);
		Ric_Rest_Client::get($fileUrl, [], $headers, $oFH);
		$this->checkServerResponse('', $headers, $tmpFilePath);
		$this->restoreResourceFromFile($tmpFilePath, $resource, $password, $overwrite);
		return true;
	}

	/**
	 * @param $encryptedFilePath
	 * @param string $resource
	 * @param string $password
	 * @param bool $overwrite
	 * @throws RuntimeException
	 * @internal param string $filePath
	 */
	protected function restoreResourceFromFile($encryptedFilePath, $resource, $password, $overwrite = true){
		$this->logDebug('downloaded as tmpFile: '.$encryptedFilePath.'['.filesize($encryptedFilePath).']');
		$filePath = $this->getDecryptedFilePath($encryptedFilePath, $password);
		if( preg_match('~^mysql://~', $resource) ){
			throw new RuntimeException('resource type mysql not implemented');
		}elseif( preg_match('~^redis://~', $resource) ){ // redis://pass@123.234.23.23:3343/mykeys_* <- dump as msgpack (ttls?)
			throw new RuntimeException('resource type mysql not implemented');
		}else{
			// restore as file
			if( file_exists($resource) AND !$overwrite ){
				throw new RuntimeException('resource ['.realpath($resource).'] (file or dir) already exists! restore skipped');
			}
			$this->logDebug('restore as file: '.$resource);
			if( !copy($filePath, $resource) ){
				throw new RuntimeException('restore as file: '.$resource.' failed!');
			}
		}
	}

	/**
	 * transfor a resource into a file
	 * file->file
	 * dir-> tar.gz->file
	 * mysql -> sql-dump-file
	 * redis -> ?
	 * -
	 * @param string $resource
	 * @throws RuntimeException
	 * @return string
	 */
	protected function getFilePathForResource($resource){
		if( is_file($resource) ){
			$this->logDebug('file resource detected');
			$filePath = $resource;
		}elseif( is_dir($resource) ){
			$this->logDebug('dir resource detected');
			$tmpTarFile = $this->getTmpFilePath('.tar.bz2');
			$command = 'tar -cjf '.$tmpTarFile.' -C '.realpath($resource).' .'; // change to dir and backup content of dir not the upper path     backup /etc/apache/ -> will back conf,sites-enabled ... not /etc/apache/conf
			$this->logDebug('dir as tar with bzip: '.$command);
			exec($command, $output, $status);
			if( $status!=0 ){
				throw new RuntimeException('tar dir failed: '.$command.' with: '.print_r($output, true), 500);
			}
			touch($tmpTarFile, filemtime(rtrim($resource, '/').'/.')); // get the dir mod-date and set it to created tar
			$this->logDebug('set modification time of tar to '.date('Y-m-d H:i:s', filemtime($tmpTarFile)));
			$filePath = $tmpTarFile;
		}elseif( $resource=='STDIN' ){
			$filePath = $this->getTmpFilePath();
			while( !feof(STDIN) ){
				$data = fread(STDIN, 1000000);
				file_put_contents($filePath, $data, FILE_APPEND);
			}
			if( filesize($filePath)==0 ){
				throw new RuntimeException('STDIN was empty, i stop here');
			}
		}elseif( preg_match('~^mysql://~', $resource) ){
			throw new RuntimeException('resource type mysql not implemented');
		}elseif( preg_match('~^redis://~', $resource) ){ // redis://pass@123.234.23.23:3343/mykeys_* <- dump as msgpack (ttls?)
			throw new RuntimeException('resource type mysql not implemented');
		}else{
			throw new RuntimeException('resource not found or unsupported type');
		}
		return $filePath;
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
	 * @return array
	 */
	public function listFiles(){
		$response = Ric_Rest_Client::get($this->buildUrl('', 'list'), [], $headers);
		$this->checkServerResponse($response, $headers);
		return json_decode($response, true);
	}

	/**
	 * check cluster health
	 * @throws RuntimeException
	 * @return string
	 */
	public function health(){
		$response = Ric_Rest_Client::get($this->buildUrl('', 'health'), [], $headers);
		$this->checkServerResponse($response, $headers);
		if( !$this->isResponseStatusOk($response) ){
			throw new RuntimeException('health check critical: '.$response);
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
	 * @return string
	 */
	public function copyServer($targetServerHostPort){
		$filesCopied = 0;
		$headers = [];
		$response = Ric_Rest_Client::get($this->buildUrl('', 'list'), [], $headers);
		$this->checkServerResponse($response, $headers);
		$fileNames = json_decode($response, true);
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
		$response = json_encode(['status' => 'OK', 'filesCopied' => $filesCopied]);
		return $response;
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
