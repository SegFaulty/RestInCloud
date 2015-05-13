<?php

/**
 * encryption
 * http://www.shellhacks.com/en/Encrypt-And-Decrypt-Files-With-A-Password-Using-OpenSSL
 * openssl enc -aes-256-cbc -salt -in file.txt -out file.txt.enc -k PASS
 * openssl enc -aes-256-cbc -d -in file.txt.enc -out file.txt -k PASS
 *
 * Class Ric_Client_Client
 */
class Ric_Client_Client{

	protected $server = '';
	protected $auth = '';
	protected $log = '';
	protected $debug = false;

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
		$this->log.= $msg.PHP_EOL;
	}

	/**
	 * @param string $msg
	 */
	protected function logDebug($msg){
		if( $this->debug ){
			$this->log.= date('Y-m-d H:i:s').' '.$msg.PHP_EOL;
echo $msg.PHP_EOL; //todo logger
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
	protected function buildUrl($fileName, $command='', $parameters=[]){
		if( $this->server=='' ){
			throw new RuntimeException('no server given');
		}
		$url = 'http://'.$this->server.'/';
		$url.= $fileName;
		if( $this->auth!='' ){
			$parameters+= ['token'=>$this->auth];  // add token
		}
		$url.= '?'.$command;
		if( !empty($parameters) ){
			$url.= '&'.http_build_query($parameters);
		}
		$this->logDebug(__METHOD__.' url:'.$url);
		return $url;
	}

	/**
	 * @param string $response
	 * @param array $headers
	 * @param string $responseFilePAth
	 * @throws RuntimeException
	 */
	protected function checkServerResponse($response, $headers, $responseFilePAth=''){
		if( !isset($headers['Http-Code']) ){
			throw new RuntimeException('no api response code');
		}
		if( $headers['Http-Code']>=400 ){
			$msg = 'Failed: with code: '.$headers['Http-Code'];
			if( $response=='' AND $responseFilePAth!='' AND file_exists($responseFilePAth) AND filesize($responseFilePAth)<100000 ){
				$response = file_get_contents($responseFilePAth);
			}
			$result = json_decode($response, true);
			if( !empty($result['error']) ){
				$msg.= ' Error: '.$result['error'];
			}else{
				$msg.= $response;
			}
			throw new RuntimeException($msg);
		}
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
	public function storeFile($filePath, $name='', $retention=null, $timestamp=null, $noSync=false){
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
		$result = Ric_Rest_Client::putFile($this->buildUrl($name, '', $params), $filePath, $headers);
		$this->checkServerResponse($result, $headers);
		return $result;
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
	public function backup($resource, $targetFileName, $password=null, $retention=null, $timestamp=null, $minReplicas=null, $minSize=1){
		if( $timestamp<=0 ){
			$timestamp = time();
		}
		$rawFilePath = $this->getFilePathForResource($resource);
		$filePath = $this->getEncryptedFilePath($rawFilePath, $password);
		if( filesize($filePath)<$minSize ){
			throw new RuntimeException('required min file size('.$minSize.') not reached (was '.filesize($filePath).')');
		}
		$sha1 = sha1_file($filePath);
		$params = [];
		$params['sha1'] = $sha1;
		$params['timestamp'] = $timestamp;
		if( $retention ){
			$params['retention'] = $retention;
		}
		$fileUrl = $this->buildUrl($targetFileName, '', $params);
		// Post
		$this->logDebug('POST refresh to: '.$fileUrl.' with timestamp: '.$timestamp.'('.date('Y-m-d H:i:s', $timestamp).')');
		$headers = [];
		$response = trim(Ric_Rest_Client::post($fileUrl, [], $headers));
		$this->checkServerResponse($response, $headers);
		if( $response!=='1' ){
			// Put
			$this->logDebug('POST refresh failed, file has to be sent via PUT');
			$headers = [];
			$response = Ric_Rest_Client::putFile($fileUrl, $filePath, $headers);
			$this->checkServerResponse($response, $headers);
			$this->logDebug('PUT result:'. $response);
		}else{
			$this->logDebug('POST refresh succeeded, no file transfer necessary');
		}
		// Verify
		$this->verify($targetFileName, $minReplicas, $sha1);
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
	public function verify($targetFileName, $minReplicas=null, $sha1=null, $minSize=null, $minTimestamp=null){
		// Verify
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
		$fileUrl = $this->buildUrl($targetFileName, 'verify', $params);
		$response = trim(Ric_Rest_Client::get($fileUrl, [], $headers));
		$this->checkServerResponse($response, $headers);
		$this->logDebug('Verify ('.$fileUrl.') result: '.$response);
		$result = json_decode($response, true);
		if( !isset($result['status']) OR $result['status']!='OK' ){
			throw new RuntimeException('verify failed: '.$response);
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
	public function restore($targetFileName, $resource, $password=null, $version=null, $overwrite=true){
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
	protected function restoreResourceFromFile($encryptedFilePath, $resource, $password, $overwrite=true){
		$this->logDebug('downloaded as tmpFile: '.$encryptedFilePath. '['.filesize($encryptedFilePath).']');
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
			throw new RuntimeException('resource type dir not implemented');
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
	 * @return string
	 * @throws RuntimeException
	 */
	protected function getEncryptedFilePath($filePath, $password){
		if( !is_file($filePath) ){
			throw new RuntimeException('file not found or not a regular file: '.$filePath);
		}

		$encryptedFilePath = $this->getTmpFilePath('.ricenc');

		$command = 'openssl enc -aes-256-cbc -salt -in '.$filePath.' -out '.$encryptedFilePath.' -k '.escapeshellarg((string) $password);
		exec($command, $output, $status);
		if( $status!=0 ){
echo '$command: '.$command.PHP_EOL;
echo 'status: '.$status.PHP_EOL;
echo '$output: '.$output;
print_r($output);
			throw new RuntimeException('encryption failed', 500);
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
echo '$command: '.$command.PHP_EOL;
echo 'status: '.$status.PHP_EOL;
echo '$output: ';
print_r($output);
			throw new RuntimeException('decryption failed', 500);
		}

		return $decryptedFilePath;
	}

	/**
	 * delete on backupClster
	 * @param string $targetFileName
	 * @param string|null $version
	 * @return string
	 */
	public function delete($targetFileName, $version=null){
		$params = [];
		if( $version!==null ){
			$params['version'] = $version;
		}
		$response = Ric_Rest_Client::delete($this->buildUrl($targetFileName, 'delete'), [], $headers);
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
		$response = Ric_Rest_Client::get($this->buildUrl('', 'info'), [], $headers);
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
	 * list files with details
	 * @return array
	 */
	public function listFileDetails(){
		$response = Ric_Rest_Client::get($this->buildUrl('', 'listDetails'), [], $headers);
		$this->checkServerResponse($response, $headers);
		return json_decode($response, true);
	}

	/**
	 * check cluster health
	 * @return string
	 */
	public function health(){
		$response = Ric_Rest_Client::get($this->buildUrl('', 'health'), [], $headers);
		$this->checkServerResponse($response, $headers);
		return $response;
	}

	/**
	 * @param $serverHostPort
	 * @return string
	 */
	public function addServer($serverHostPort){
		$response = Ric_Rest_Client::post($this->buildUrl('', '', ['action'=>'addServer', 'addServer'=>$serverHostPort]), [], $headers);
		$this->checkServerResponse($response, $headers);
		return $response;
	}

	/**
	 * @param $serverHostPort
	 * @return string
	 */
	public function removeServer($serverHostPort){
		$response = Ric_Rest_Client::post($this->buildUrl('', '', ['action'=>'removeServer', 'removeServer'=>$serverHostPort]), [], $headers);
		$this->checkServerResponse($response, $headers);
		return $response;
	}

	/**
	 * @param $serverHostPort
	 * @return string
	 */
	public function joinCluster($serverHostPort){
		$response = Ric_Rest_Client::post($this->buildUrl('', '', ['action'=>'joinCluster', 'joinCluster'=>$serverHostPort]), [], $headers);
		$this->checkServerResponse($response, $headers);
		return $response;
	}

	/**
	 * @return string
	 */
	public function leaveCluster(){
		$response = Ric_Rest_Client::post($this->buildUrl('', '', ['action'=>'leaveCluster']), [], $headers);
		$this->checkServerResponse($response, $headers);
		return $response;
	}


	/**
	 * @param string $command
	 * @return string
	 */
	public function getHelp($command='global'){
		$helpString = '';
		// extract from README-md
		$readMePath = __DIR__.'/README.md';
		if( file_exists($readMePath) ){
			$helpString = file_get_contents($readMePath);
		}
		if($command and preg_match('~\n## Help '.preg_quote($command, '~').'(.*?)(\n## |$)~s', $helpString, $matches) ){
			$helpString = $matches[1];
		}
		return $helpString;
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
	public function getTmpFilePath($extension=''){
		$tmpFile = $this->tmpFileDir;
		if( $tmpFile=='' ){
			$tmpFile = sys_get_temp_dir();
		}
		$tmpFile.= '/_'.__CLASS__.'_'.uniqid('', true).$extension;
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
