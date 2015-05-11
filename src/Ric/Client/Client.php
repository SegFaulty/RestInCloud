<?php

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
		$this->log('server:'.$serverHostPort);
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
		$this->debug = true AND $bool;
	}

	/**
	 * @param string $msg
	 */
	protected function log($msg){
		$this->log.= $msg.PHP_EOL;
echo $msg.PHP_EOL; //todo logger
	}

	/**
	 * @param string $msg
	 */
	protected function logDebug($msg){
		if( $this->debug ){
			$this->log.= date('Y-m-d H:i:s').' '.$msg.PHP_EOL;
		}
	}

	/**
	 * build api url
	 * @param string $fileName
	 * @param string $command
	 * @param array $parameters
	 * @return string
	 */
	protected function buildUrl($fileName, $command='', $parameters=[]){
		$url = 'http://'.$this->server.'/';
		$url.= $fileName;
		if( $this->auth!='' ){
			$parameters+= ['token'=>$this->auth];  // add token
		}
		if( !empty($parameters) ){
			$url.= '?'.$command.'&'.http_build_query($parameters);
		}
		$this->log(__METHOD__.' url:'.$url);
		return $url;
	}

	/**
	 * @param string $response
	 * @param array $headers
	 * @param string $responseFilePAth
	 * @throws RuntimeException
	 */
	protected function checkServerResult($response, $headers, $responseFilePAth=''){
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
		$this->checkServerResult($result, $headers);
		return $result;
	}

	/**
	 *
	 * @param string $resource
	 * @param string $targetFileName
	 * @param string $retention
	 * @param int $timestamp
	 * @param int $minReplicas
	 * @param int $minSize
	 * @throws RuntimeException
	 * @return bool
	 */
	public function backup($resource, $targetFileName, $retention=null, $timestamp=null, $minReplicas=null, $minSize=1){
		if( $timestamp<=0 ){
			$timestamp = time();
		}
		$filePath = $this->getFilePathForResource($resource);
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
		$response = trim(Ric_Rest_Client::post($fileUrl, [], $headers));
		$this->checkServerResult($response, $headers);
		if( $response!=='1' ){
			// Put
			$this->logDebug('POST refresh failed, file has to be sent via PUT');
			$response = Ric_Rest_Client::putFile($fileUrl, $filePath, $headers);
			$this->checkServerResult($response, $headers);
			$this->logDebug('PUT result:'. $response);
		}else{
			$this->logDebug('POST refresh succeeded, no file transfer necessary');
		}
		// Verify
		$params = [];
		$params['sha1'] = $sha1;
		if( $minReplicas!==0 ){
			$params['minReplicas'] = $minReplicas;
		}
		$fileUrl = $this->buildUrl($targetFileName, 'verify', $params);
		$response = trim(Ric_Rest_Client::get($fileUrl, [], $headers));
		$this->checkServerResult($response, $headers);
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
	 * @param string $version
	 * @throws RuntimeException
	 * @return bool
	 */
	public function restore($targetFileName, $resource, $version=null){
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
		$this->checkServerResult('', $headers, $tmpFilePath);
		$this->restoreResourceFromFile($tmpFilePath, $resource);
		return true;
	}

	/**
	 * @param string $filePath
	 * @param string $resource
	 * @throws RuntimeException
	 */
	protected function restoreResourceFromFile($filePath, $resource){
		$this->logDebug('downloaded as tmpFile: '.$filePath. '['.filesize($filePath).']');
		if( file_exists($resource) ){
			throw new RuntimeException('resource ['.realpath($resource).'] (file or dir) already exists! restore skipped');
		}
		if( preg_match('~^mysql://~', $resource) ){
			throw new RuntimeException('resource type mysql not implemented');
		}elseif( preg_match('~^redis://~', $resource) ){ // redis://pass@123.234.23.23:3343/mykeys_* <- dump as msgpack (ttls?)
			throw new RuntimeException('resource type mysql not implemented');
		}else{
			// restore as file
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
	 * @param string $resource
	 * @return string
	 * @throws RuntimeException
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
	 * @param string $command
	 * @return string
	 */
	public function getHelp($command){
		$helpString = 'help '.$command;
		return $helpString;
	}


	static protected $tmpFilePaths = [];

	/**
	 * return filePath
	 * file will be delete on script termination (via register_shutdown_function deleteTmpFiles)
	 * extension ".jpg" for imagemagick zum beispiel
	 * @param string $extension
	 * @return string
	 */
	static public function getTmpFilePath($extension=''){
		$tmpFile = sys_get_temp_dir().'/_'.__CLASS__.'_'.uniqid('', true).$extension;
		register_shutdown_function(array(__CLASS__, 'deleteTmpFiles'));
		self::$tmpFilePaths[] = $tmpFile;
		return $tmpFile;
	}

	/**
	 * bereinige all tmpFiles
	 */
	static public function deleteTmpFiles(){
		foreach( self::$tmpFilePaths as $tmpFilePath ){
			if( file_exists($tmpFilePath) ){
				unlink($tmpFilePath);
			}
		}
	}
}
