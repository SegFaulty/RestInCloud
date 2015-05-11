<?php


require_once __DIR__.'/../Rest/Client.php';
date_default_timezone_set(@date_default_timezone_get()); // Suppress DateTime warnings


// Helper syntactic sugar function
class H{
	/* getKeyIfSet */ static public function getIKS(&$array, $key, $default=null){return (array_key_exists($key, $array) ? $array[$key] : $default );}
	/* getRequestParameter */ static public function getRP($key, $default=null){return H::getIKS($_REQUEST, $key, $default);}
	/* json_encode */ static public function json($array){return json_encode($array, JSON_PRETTY_PRINT+JSON_UNESCAPED_SLASHES).PHP_EOL;}
}

// php server
if( php_sapi_name()=='cli-server' ){

	ini_set('display_errors', true);
	ini_set('log_errors', true);
	ini_set("error_log", '/home/www/ricServerErrors.log');

	$ricServer = new Ric_Server_Server(H::getIKS($_ENV, 'Ric_config', './config.json'));
	$ricServer->handleRequest();
	return true; // if false, the internal server will serve the REQUEST_URI .. this is dangerous

}

// cli commands
if( php_sapi_name()=='cli' ){

	switch(H::getIKS($argv,1)){
		case 'purge':
			Ric_Server_Server::cliPurge($argv);
			break;
		default:
			die(
				'please start it as webserver:'."\n"
				.' Ric_config=./config.json php -d variables_order=GPCSE -S 0.0.0.0:3070 '.__FILE__."\n"
				.'   OR   '."\n"
				.'php '.__FILE__.' purge /path/to/storeDir {maxTimestamp}'."\n"
				.'  to purge all files marked for deletion (with fileMtime < maxTimestamp)'."\n"
			);
	}
	return 1;
}

// use in http or what ever sapi
# $ricServer = new Ric_Server_Server('path_to/config.json'));
# $ricServer->handleRequest();




/**
 * Class Ric_Server_Server
 */
class Ric_Server_Server {

	static $markDeletedTimestamp = 1422222222; // 2015-01-25 22:43:42

	protected $defaultConfig = [
		'hostPort' => '', // h172.17.8.101:3070
		'storeDir' => '/tmp/ric/',
		'quota' => 0,
		'servers' => [],
		'adminToken' => 'admin',
		'writerToken' => 'writer',
		'readerToken' => '',
		'defaultRetention' => self::RETENTION__LAST3,
	];

	protected $config = [];

	const RETENTION__OFF = 'off';
	const RETENTION__LAST3 = 'last3';
	const RETENTION__LAST7 = 'last7';
	const RETENTION__3L7D4W12M = '3l7d4w12m';

	/**
	 * construct
	 */
	public function __construct($configFilePath=''){
		$this->loadConfig($configFilePath);
		if( !is_dir($this->config['storeDir']) OR !is_writable($this->config['storeDir']) ){
			throw new RuntimeException('document root is not a writable dir!');
		}
	}

	/**
	 * handle GETs
	 */
	public function handleRequest(){
		try{
			$this->auth('reader', true);
			if( $_SERVER['REQUEST_METHOD']=='PUT' ){
				$this->handlePutRequest();
			}elseif( $_SERVER['REQUEST_METHOD']=='POST' OR H::getRP('method')=='post' ){
				$this->handlePostRequest();
			}elseif( $_SERVER['REQUEST_METHOD']=='GET' ){
				$this->handleGetRequest();
			}elseif( $_SERVER['REQUEST_METHOD']=='DELETE' OR H::getRP('method')=='delete' ){
				if( $this->auth('writer') ){
					$this->actionDelete();
				}
			}else{
				throw new RuntimeException('unsupported http-method', 400);
			}
		}catch(Exception $e){
			if( $e->getCode()===400 ){
				header('HTTP/1.1 400 Bad Request', true, 400);
			}elseif( $e->getCode()===403 ){
				header('HTTP/1.1 403 Forbidden', true, 403);
			}elseif( $e->getCode()===404 ){
				header('HTTP/1.1 404 Not found', true, 404);
			}elseif( $e->getCode()===507 ){
				header('HTTP/1.1 507 Insufficient Storage', true, 507);
			}else{
				header('HTTP/1.1 500 Internal Server Error', true, 500);
			}
			header('Content-Type: application/json');
			echo H::json(['error'=>$e->getMessage()]);

		}
	}

	/**
	 * execute cli command
	 * @param array $argv
	 * @throws RuntimeException
	 */
	static public function cliPurge($argv){
		$storeDir = $argv[2];
		$maxTimestamp = $argv[3];
		if( $maxTimestamp<=1 OR $maxTimestamp>time()+86400 ){
			throw new RuntimeException('invalid timestamp (now:'.time().')');
		}
		$ricServer = new Ric_Server_Server($storeDir);
		echo H::json($ricServer->purge($maxTimestamp));
	}

	/**
	 * physically delete files marked for deletion
	 *
	 * @param $maxTimestamp
	 * @throws RuntimeException
	 * @return array
	 */
	public function purge($maxTimestamp){
		$result = [];
		$result['status'] = 'OK';
		$result['msg'] = '';
		$result['checkedFiles'] = 0;
		$result['deletedFiles'] = 0;
		$result['deletedBytes'] = 0;
		$result['runTime'] = microtime(true);
		$dirIterator = new RecursiveDirectoryIterator($this->config['storeDir']);
		$iterator = new RecursiveIteratorIterator($dirIterator, RecursiveIteratorIterator::SELF_FIRST);
		foreach( $iterator as $splFileInfo ){ /** @var SplFileInfo $splFileInfo */
			if( $splFileInfo->isFile() ){
				if( !strstr($splFileInfo->getFilename(), '___') ){
					throw new RuntimeException('unexpected file found (), for safty reason i quite here!');
				}
				$result['checkedFiles']++;
				$fileTimestamp = filemtime($splFileInfo->getRealPath());
				if( $fileTimestamp<=$maxTimestamp AND $fileTimestamp==self::$markDeletedTimestamp ){
					$fileSize = filesize($splFileInfo->getRealPath());
					if( unlink($splFileInfo->getRealPath()) ){
						$result['deletedFiles']++;
						$result['deletedBytes']+= $fileSize;
					}else{
						$result['status'] = 'WARNING';
						$result['msg'] = 'unlink failed';
					}
				}
			}
		}
		$result['runTime'] = round(microtime(true)-$result['runTime'],3);
		return $result;
	}

	/**
	 * load config default->given config -> docRoot/intern/config.json
	 */
	protected function loadConfig($configFilePath=''){

		if( file_exists($configFilePath) ){
			$localConfig = json_decode(file_get_contents($configFilePath), true);
			if( !is_array($localConfig) ){
				throw new RuntimeException('config.json is invalid (use "{}" for empty config)');
			}
			$this->config = $localConfig + $this->config;
		}

		$this->config = $this->config + $this->defaultConfig;

		$this->config['storeDir'] = rtrim($this->config['storeDir'],DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR; // make sure of trailing slash

		if( file_exists($this->config['storeDir'].'/intern/config.json') ){
			$this->config = json_decode(file_get_contents($this->config['storeDir'].'/intern/config.json'), true) + $this->config;
		}

	}

	/**
	 * set, update, remove (null) a value in runtimeConfig (and config)
	 * @param string $key
	 * @param string $value
	 */
	protected function setRuntimeConfig($key, $value){
		$runtimeConfig = [];
		if( file_exists($this->config['storeDir'].'/intern/config.json') ){
			$runtimeConfig = json_decode(file_get_contents($this->config['storeDir'].'/intern/config.json'), true);
		}
		if( $value===null ){
			if( isset($runtimeConfig[$key]) ){
				unset($runtimeConfig[$key]);
			}
		}else{
			$runtimeConfig[$key] = $value;
			$this->config[$key] = $value;
		}
		if( !is_dir($this->config['storeDir'].'/intern/') ){
			mkdir($this->config['storeDir'].'/intern/');
		}
		file_put_contents($this->config['storeDir'].'/intern/config.json', H::json($runtimeConfig));
	}

	/**
	 * handle PUT
	 */
	protected function handlePutRequest(){
		$this->auth('writer');
		$result = 'OK';
		$retention = H::getRP('retention', self::RETENTION__LAST3);
		$timestamp = H::getRP('timestamp', time());
		$noSync = (bool) H::getRP('noSync');

		// read stream to tmpFile
		$tmpFilePath = $tmpFile = sys_get_temp_dir().'/_'.__CLASS__.'_'.uniqid('', true);
		$putData = fopen("php://input", "r");
		$fp = fopen($tmpFilePath, "w");
		$bytesCopied = stream_copy_to_stream($putData, $fp);
		fclose($fp);
		fclose($putData);
echo $bytesCopied.' bytes'.PHP_EOL;
echo filesize($tmpFilePath).' tmp bytes'.PHP_EOL;


		// get correct filePath
		$filePath = $this->getFilePath(sha1_file($tmpFile));

		// init splitDirectory
		$fileDir = dirname($filePath);
		if( !is_dir($fileDir) ){
			if( !mkdir($fileDir, 0755, true) ){
				throw new RuntimeException('make splitDirectory failed: '.$fileDir);
			}
		}

		// move file, set modTime
		rename($tmpFilePath, $filePath);

		// check quota
		if( $this->config['quota']>0 ){
			if( $this->getDirectorySize()>$this->config['quota']*1024*1024 ){
				unlink($filePath);
				throw new RuntimeException('Quota exceeded!', 507);
			}
		}

		// replicate
		$syncResult = $this->syncFile($filePath, $timestamp, $retention, $noSync);
		if( $syncResult!='' ){
			$result = 'WARNING'.' :'.$syncResult;
		}
		// todo verify

		$this->executeRetention($filePath, $retention);

		header('HTTP/1.1 201 Created', true, 201);
		echo $result.PHP_EOL;
	}

	/**
	 * handle get
	 */
	protected function handleGetRequest(){
		$action = '';
		if( preg_match('~^(\w+).*~', H::getIKS($_SERVER, 'QUERY_STRING'), $matches) ){
			$action = $matches[1];
		}
		if( $_SERVER['REQUEST_URI']=='/' ){ // homepage
			$this->actionHelp();
		}elseif( parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)=='/' ){ // no file
			if( $action=='list' AND $this->auth('admin') ){
				$this->actionList();
			}elseif( $action=='listDetails' AND $this->auth('admin') ){
				$this->actionList(true);
			}elseif( $action=='help' ){
				$this->actionHelp();
			}elseif( $action=='info' ){
				$this->actionInfo();
			}elseif( $action=='health' ){
				$this->actionHealth();
			}elseif( $action=='phpInfo' ){
				phpinfo();
			}else{
				throw new RuntimeException('unknown action', 400);
			}
		}elseif( $action=='size' ){
			echo filesize($this->getFilePath());
		}elseif( $action=='verify' ){
			$this->actionVerify();
		}elseif( $action=='list' ){
			$this->actionListVersions();
		}elseif( $action=='head' ){
			$this->actionHead();
		}elseif( $action=='grep' ){
			$this->actionGrep();
		}elseif( $action=='help' ){
			$this->actionHelp();
		}elseif( $action=='' AND ($filePath=$this->getFilePath()) ){
			$this->actionSendFile();
		}else{
			throw new RuntimeException('unknown action or no file given', 400);
		}
	}

	/**
	 * handle POST, file refresh
	 */
	protected function handlePostRequest(){
		$this->auth('writer');
		$action = H::getRP('action');
		if( parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)=='/' ){ // homepage
			// post actions
			if( $action=='addServer' AND $this->auth('admin') ){
				$this->actionAddServer();
			}elseif( $action=='removeServer' AND $this->auth('admin') ){
				$this->actionRemoveServer();
			}elseif( $action=='joinCluster' AND $this->auth('admin') ){
				$this->actionJoinCluster();
			}elseif( $action=='leaveCluster' AND $this->auth('admin') ){
				$this->actionLeaveCluster();
			}else{
				throw new RuntimeException('unknown action or no file given [Post]', 400);
			}
		}else{
			// not "/" .. this is a file, refresh action
			$this->actionPostRefresh();
		}
	}

	/**
	 * check and refresh a with a post request
	 * @throws RuntimeException
	 */
	protected function actionPostRefresh(){
		// not / .. this is a file, refresh action
		$result = '0';
		$sha1 = H::getRP('sha1');
		$retention = H::getRP('retention', '');
		$timestamp = H::getRP('timestamp', time());
		$noSync = (bool) H::getRP('noSync');

		if( $sha1=='' ){
			throw new RuntimeException('?sha1=1342.. is required', 400);
		}

		$filePath = $this->getFilePath($sha1);
		if( file_exists($filePath) ){
			$syncResult = $this->syncFile($filePath, $timestamp, $retention, $noSync);
			$this->executeRetention($filePath, $retention);
			if( $syncResult=='' ){
				$result = '1';
			}
		}else{
			// file not found  > $result = '0';
		}
		echo $result.PHP_EOL;
	}

	/**
	 * register a new file in store and set timestamp, sync other server
	 * @param string $filePath
	 * @param int $timestamp
	 * @param string $retention
	 * @param bool $noSync
	 * @return array|string
	 */
	protected function syncFile($filePath, $timestamp, $retention, $noSync=false){
		$result = '';
		touch($filePath, $timestamp);
		if( !$noSync ){
			// SYNC
			/** @noinspection PhpUnusedLocalVariableInspection */
			list($fileName, $version) = $this->extractVersionFromFullFileName($filePath);
			foreach( $this->config['servers'] as $server ){
				try{
					$serverUrl = 'http://'.$server.'/';
					// try to refresh file
					$url = $serverUrl.$fileName.'?sha1='.sha1_file($filePath).'&timestamp='.$timestamp.'&retention='.$retention.'&noSync=1&token='.$this->config['writerToken'];
					$response = Ric_Rest_Client::post($url);
					if( trim($response)!='1' ){
						// refresh failed, upload
						$url = $serverUrl.$fileName.'?timestamp='.$timestamp.'&retention='.$retention.'&noSync=1&token='.$this->config['writerToken'];
						$response = Ric_Rest_Client::putFile($url, $filePath);
						if( trim($response)!='OK' ){
							$result = trim($result."\n".'failed to upload to '.$server.' :'.$response);
						}
					}
				}catch(Exception $e){
					$result = trim($result."\n".'failed to upload to '.$server);
				}
			}
		}
		return $result;
	}

	/**
	 * user admin>writer>reader
	 * @param string $user
	 * @param bool $isRequired
	 * @return bool
	 * @throws RuntimeException
	 */
	protected function auth($user='reader', $isRequired=true){
		$isAuth = false;

		$userRole = 'guest';

		$token = H::getRP('token');

		if(  $token==$this->config['readerToken'] OR $this->config['readerToken']=='' ){
			$userRole = 'reader';
		}
		if(  $token==$this->config['writerToken'] OR $this->config['writerToken']=='' ){
			$userRole = 'writer';
		}
		if(  $token==$this->config['adminToken'] OR $this->config['adminToken']=='' ){
			$userRole = 'admin';
		}

		if( $user=='admin' AND $userRole=='admin' ){
			$isAuth = true;
		}elseif( $user=='writer' AND in_array($userRole, ['writer', 'admin']) ){
			$isAuth = true;
		}elseif( $user=='reader' AND in_array($userRole, ['reader', 'writer', 'admin']) ){
			$isAuth = true;
		}

		if( !$isAuth AND $isRequired ){
			throw new RuntimeException('login needed', 403);
		}

		return $isAuth;
	}

	/**
	 * @throws RuntimeException
	 */
	protected function actionAddServer(){
		$server = H::getRP('addServer');


		$info = json_decode(Ric_Rest_Client::get('http://'.$server.'/', ['info'=>1,'token'=>$this->config['readerToken']]), true);
		if( $info AND H::getIKS($info, 'serverTimestamp') ){
			$this->config['servers'][] = $server;
			$this->setRuntimeConfig('servers', $this->config['servers']);
		}else{
			throw new RuntimeException('server is not responding properly', 400);
		}
		header('Content-Type: application/json');
		echo H::json(['Status' => 'OK']);
	}

	/**
	 * remove selected or "all" servers
	 * @throws RuntimeException
	 */
	protected function actionRemoveServer(){
		$server = H::getRP('removeServer');
		if( $server=='all' ){
			$servers = [];
		}else{
			$servers = array_diff($this->config['servers'], [$server]);
		}
		$this->setRuntimeConfig('servers', $servers);

		header('Content-Type: application/json');
		echo H::json(['Status' => 'OK']);
	}

	/**
	 * join a existing cluster
	 * get all servers of the given clusterMember an send an addServer to all
	 * if it fails, the cluster is in inconsistent state, send leaveCluster command
	 * @throws RuntimeException
	 */
	protected function actionJoinCluster(){
		$server = H::getRP('joinCluster');
		$ownServer = $this->getOwnHostPort();
		$response = Ric_Rest_Client::get('http://' . $server . '/', ['info' => 1, 'token' => $this->config['adminToken']]);
		$info = json_decode($response, true);
		if( isset($info['config']['servers']) ){
			$servers = $info['config']['servers'];
			$joinedServers = [];
			$servers[] = $server;
			foreach( $servers as $clusterServer ){
				$response = Ric_Rest_Client::post('http://' . $clusterServer . '/', ['action' => 'addServer', 'addServer' => $ownServer, 'token' => $this->config['adminToken']]);
				$result = json_decode($response, true);
				if( H::getIKS($result, 'Status')!='OK' ){
					throw new RuntimeException('join cluster failed! addServer to '.$clusterServer.' failed! ['.$response.'] Inconsitent cluster state! I\'m added to this servers (please remove me): '.join('; ', $joinedServers), 400);
				}
				$joinedServers[] = $clusterServer;
			}
			$this->config['servers'] = $servers;
			$this->setRuntimeConfig('servers', $this->config['servers']);

			// todo  pull a dump and restore

		}else{
			throw new RuntimeException('cluster node is not responding properly', 400);
		}
		header('Content-Type: application/json');
		echo H::json(['Status' => 'OK']);
	}

	/**
	 * leaving a cluster
	 * send removeServer to all servers
	 * if it fails, the cluster is in inconsistent state, send leaveCluster command
	 * @throws RuntimeException
	 */
	protected function actionLeaveCluster(){
		$ownServer = $this->getOwnHostPort();
		$errorMsg = '';
		$leavedServers = [];
		foreach( $this->config['servers'] as $clusterServer ){
			$response = Ric_Rest_Client::post('http://' . $clusterServer . '/', ['action' => 'removeServer', 'removeServer' => $ownServer, 'token' => $this->config['adminToken']]);
			$result = json_decode($response, true);
			if( H::getIKS($result, 'Status')!='OK' ){
				$errorMsg.= 'removeServer failed from '.$clusterServer.' failed! ['.$response.']';
			}else{
				$leavedServers[] = $clusterServer;
			}
		}
		$this->config['servers'] = [];
		$this->setRuntimeConfig('servers', $this->config['servers']);

		if( $errorMsg!='' ){
			throw new RuntimeException('leaveCluster failed! '.$errorMsg.' Inconsitent cluster state! (please remove me manually) succesfully removed from: '.join('; ', $leavedServers), 400);
		}
		header('Content-Type: application/json');
		echo H::json(['Status' => 'OK']);
	}


	/**
	 * mark one or all versions of the File as deleted
	 */
	protected function actionDelete(){
		$filePath = $this->getFilePath();
		$filesDeleted = 0;
		if( !H::getRP('version') ){
			$filesDeleted+= $this->markFileDeleted($filePath);
		}else{
			$baseFilePath = preg_replace('~___\w+$~', '', $filePath);
			foreach( $this->getAllVersions($baseFilePath) as $version=>$timestamp){
				$filesDeleted+= $this->markFileDeleted($baseFilePath.'___'.$version);
			}
		}
		header('Content-Type: application/json');
		echo H::json(['filesDeleted' => $filesDeleted]);
	}

	/**
	 * list files or version of file
	 * @throws RuntimeException
	 */
	protected function actionVerify(){
		$result = [];
		$result['status'] = 'OK';
		$result['msg'] = '';
		$filePath = $this->getFilePath();

		$sha1 = H::getRP('sha1', '');
		$minSize = H::getRP('minSize', 1);
		$minTimestamp = H::getRP('minTimestamp', 0); // default no check
		$minReplicasDefault = max(1, count($this->config['servers'])-1); // min 1, or 1 invalid of current servers
		$minReplicas = H::getRP('minReplicas', $minReplicasDefault); // if parameter omitted, don't check replicas!!!! or deadlock

		$fileInfo = $this->getFileInfo($filePath);
		$fileInfo['replicas'] = false;
		if( $minReplicas>0 ){
			$fileInfo['replicas'] = $this->getReplicaCount($filePath);
		}
		if( $sha1!='' AND $fileInfo['sha1']!=$sha1 ){
			$result['status'] = 'CRITICAL';
			$result['msg'] = trim($result['msg'].PHP_EOL.'unmatched sha1');
		}
		if( $fileInfo['size']<$minSize ){
			$result['status'] = 'CRITICAL';
			$result['msg'] = trim($result['msg'].PHP_EOL.'size less then expected ('.$fileInfo['size'].'/'.$minSize.')');
		}
		if( $minTimestamp>0 AND $fileInfo['timestamp']<$minTimestamp ){
			$result['status'] = 'CRITICAL';
			$result['msg'] = trim($result['msg'].PHP_EOL.'file is outdated ('.$fileInfo['timestamp'].'/'.$minTimestamp.')');
		}
		if( $minReplicas>0 AND $fileInfo['replicas']<$minReplicas ){
			$result['status'] = 'CRITICAL';
			// wenn wir mindestens replikat haben und nur eins fehlt, dann warning (das wird mutmasslich gerade gelöst)
			if( $fileInfo['replicas']>0 AND $fileInfo['replicas']>=$minReplicas-1 ){
				$result['status'] = 'WARNING';
			}
			$result['msg'] = trim($result['msg'].PHP_EOL.'not enough replicas ('.$fileInfo['replicas'].'/'.$minReplicas.')');
		}
		$result['fileInfo'] = $fileInfo;
		header('Content-Type: application/json');
		echo H::json($result);
	}

	/**
	 * @param string $filePath
	 * @return int
	 */
	protected function getReplicaCount($filePath){
		$replicas = 0;
		list($fileName, $version) = $this->extractVersionFromFullFileName($filePath);
		$sha1 = sha1_file($filePath);
		foreach( $this->config['servers'] as $server ){
			try{
				$serverUrl = 'http://'.$server.'/';
				// verify file
				$url = $serverUrl.$fileName.'?verify&version='.$version.'&sha1='.$sha1.'&minReplicas=0&token='.$this->config['readerToken']; // &minReplicas=0  otherwise loopOfDeath
				$response = json_decode(Ric_Rest_Client::get($url), true);
				if( H::getIKS($response, 'status')=='OK' ){
					$replicas++;
				}
			}catch(Exception $e){
				// unwichtig
			}
		}
		return $replicas;
	}

	/**
	 * list files
	 * @throws RuntimeException
	 */
	protected function actionList($details=false){
		$pattern = (isset($_REQUEST['pattern']) ? self::validateRegex($_REQUEST['pattern']) : '' );
		$showDeleted = H::getRP('showDeleted');
		$start = H::getRP('start', 0);
		$limit = min(1000, H::getRP('limit', 100));

		$lines = [];
		$dirIterator = new RecursiveDirectoryIterator($this->config['storeDir']);
		$iterator = new RecursiveIteratorIterator($dirIterator, RecursiveIteratorIterator::SELF_FIRST);
		$index = -1;
		foreach( $iterator as $splFileInfo ){ /** @var SplFileInfo $splFileInfo */
			if( $splFileInfo->getPath()==$this->config['storeDir'].'intern' ){
				continue; // skip our internal files
			}
			if( $splFileInfo->isFile() ){
				/** @noinspection PhpUnusedLocalVariableInspection */
				list($fileName, $version) = $this->extractVersionFromFullFileName($splFileInfo->getFilename());
				if( $pattern!='' AND !preg_match($pattern, $fileName) ){
					continue;
				}
				$fileInfo = $this->getFileInfo($splFileInfo->getRealPath());
				if( $fileInfo['timestamp']!=self::$markDeletedTimestamp OR $showDeleted ){
					$index++;
					if( $index<$start ){
						continue;
					}
					if( $details ){
						$lines[] = ['index' => $index] + $fileInfo;
					}else{
						if( !in_array($fileName, $lines) ){
							$lines[] = $fileName;
						}
					}
					if( count($lines)>=$limit ){
						break;
					}
				}
			}
		}

		header('Content-Type: application/json');
		echo H::json($lines);
	}

	/**
	 * list versions of file
	 * @throws RuntimeException
	 */
	protected function actionListVersions(){
		$showDeleted = H::getRP('showDeleted');
		$limit = min(1000, H::getRP('limit', 100));

		$filePath = $this->getFilePath();

		$lines = [];
		$baseFilePath = preg_replace('~___\w+$~', '', $filePath);
		$index = -1;
		foreach( $this->getAllVersions($baseFilePath, $showDeleted) as $version=>$timeStamp ){
			$filePath = $baseFilePath.'___'.$version;
			$index++;
			if( $limit<=$index ){
				break;
			}
			$lines[] = ['index' => $index] + $this->getFileInfo($filePath);
		}
		header('Content-Type: application/json');
		echo H::json($lines);
	}

	/**
	 * todo merge with grep
	 * @throws RuntimeException
	 */
	protected function actionHead(){
		$filePath = $this->getFilePath();
		$fp = gzopen($filePath, 'r');
		if( !$fp ){
			throw new RuntimeException('open file failed');
		}
		$lines = H::getRP('head', 10);
		if( $lines==0 ){
			$lines = 10;
		}
		while($lines-- AND ($line = gzgets($fp, 100000))!==false){
			echo $line;
		}
		gzclose($fp);
	}

	/**
	 * @throws RuntimeException
	 */
	protected function actionGrep(){
		$filePath = $this->getFilePath();
		$fp = gzopen($filePath, 'r');
		if( !$fp ){
			throw new RuntimeException('open file failed');
		}
		// grep
		$regex = self::validateRegex(H::getRP('grep'));
		while(($line = gzgets($fp,100000))){
			if( preg_match($regex, $line) ){
				echo $line;
			}
		}
		gzclose($fp);
	}

	/**
	 * get server info
	 */
	protected function actionInfo(){
		header('Content-Type: application/json');
		echo H::json($this->buildInfo());
	}

	/**
	 * get server info
	 */
	protected function buildInfo(){
		$info['serverTimestamp'] = time();
		$directorySize = $this->getDirectorySize();
		$directorySizeMb = ceil($directorySize /1024/1024); // IN MB
		$info['usageByte'] = $directorySize;
		$info['usage'] = $directorySizeMb;
		$info['quota'] = $this->config['quota'];
		if( $this->config['quota']>0 ){
			$info['quotaLevel'] = ceil($directorySizeMb/$this->config['quota']*100);
			$info['quotaFreeLevel'] = max(0,min(100,100-ceil($directorySizeMb/$this->config['quota']*100)));
			$info['quotaFree'] = max(0, intval($this->config['quota']-$directorySizeMb));
		}
		// only for admins
		if( $this->auth('admin', false) ){
			$info['config'] = $this->config;
			$info['runtimeConfig'] = false;
			if( file_exists($this->config['storeDir'].'intern/config.json') ){
				$info['runtimeConfig'] = json_decode(file_get_contents($this->config['storeDir'].'intern/config.json'), true);
			}
			$info['defaultConfig'] = $this->defaultConfig;
		}
		return $info;
	}

	/**
	 * get server info
	 */
	protected function actionHealth(){
		$status = 'OK';
		$msg = '';

		$failedServers = [];
		$serverInfos = [];
		$serverInfos[] = $this->buildInfo();
		foreach( $this->config['servers'] as $server ){
			try{
				$url = 'http://'.$server.'/?info&token='.$this->config['adminToken'];
				$result = json_decode(Ric_Rest_Client::get($url), true);
				if( !array_key_exists('usageByte', $result) ){
					throw new HttpRuntimeException('info failed');
				}
				if( array_key_exists('quotaFreeLevel', $result) AND $result['quotaFreeLevel'] ){
					throw new HttpRuntimeException('quotaFreeLevel:'. $result['quotaFreeLevel'].'%');
				}
				$serverInfos[] = $result;
			}catch(Exception $e){
				$failedServers[$server] = $e->getMessage();
			}
		}
		if( !empty($failedServers) ){
			$status = 'WARNING';
			foreach( $failedServers as $server=>$serverError ){
				$msg.= $server.' '.$serverError.PHP_EOL;
			}
		}
		if( count($serverInfos)<2 OR count($failedServers)>1 ){
			$status = 'CRITICAL';
			$msg.= 'replication critical servers running: '.count($serverInfos).' remoteServer configured: '.count($this->config['servers']).PHP_EOL;
		}

		echo $status.PHP_EOL.$msg;
	}

	/**
	 * help
	 */
	protected function actionHelp(){
		$helpString = '';
		// extract from README-md
		$readMePath = __DIR__.'/../../../README.md';
		if( file_exists($readMePath) and preg_match('~\n## Help(.*?)(\n## |$)~s', file_get_contents($readMePath), $matches) ){
			$helpString = $matches[1];
		}
		$helpString = str_replace('my.coldstore.server.de', $_SERVER['HTTP_HOST'], $helpString);
		$retentions = '';
		$retentions.= '     '.self::RETENTION__OFF.' : keep only the last version'.PHP_EOL;
		$retentions.= '     '.self::RETENTION__LAST3.' : keep last 3 versions'.PHP_EOL;
		$retentions.= '     '.self::RETENTION__LAST7.' : keep last 7 versions'.PHP_EOL;
		$retentions.= '     '.self::RETENTION__3L7D4W12M.' : keep last 3 versions then last of 7 days, 4 weeks, 12 month (max 23 Versions)'.PHP_EOL;
		$helpString = str_replace('{retentionList}', $retentions, $helpString);
		echo '<pre>'.htmlentities($helpString).'</pre>';
	}

	/**
	 * send an existing file
	 */
	protected function actionSendFile(){
		$filePath = $this->getFilePath();
		/** @noinspection PhpUnusedLocalVariableInspection */
		list($fileName, $version) = $this->extractVersionFromFullFileName($filePath);
		$lastModified = gmdate('D, d M Y H:i:s \G\M\T', filemtime($filePath));
		$eTag = sha1_file($filePath);
		// 304er support
		$ifMod = isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) ? $_SERVER['HTTP_IF_MODIFIED_SINCE'] == $lastModified : null;
		$ifTag = isset($_SERVER['HTTP_IF_NONE_MATCH']) ? $_SERVER['HTTP_IF_NONE_MATCH'] == $eTag : null;
		if (($ifMod || $ifTag) && ($ifMod !== false && $ifTag !== false)) {
			header('HTTP/1.0 304 Not Modified');
		} else {
			header('Content-Type: application/octet-stream');
			header('Content-Disposition: attachment; filename="'.$fileName.'"');
			header('Content-Length: '.filesize($filePath));
			header('Last-Modified:'.$lastModified);
			header('ETag: '.$eTag);
			readfile($filePath);
		}
	}

	/**
	 * @param string $filePath
	 * @param string $retention
	 * @throws RuntimeException
	 */
	protected function executeRetention($filePath, $retention){
		$baseFilePath = preg_replace('~___\w+$~', '', $filePath);
		$allVersions = $this->getAllVersions($baseFilePath);
		$deleteFilePaths = [];
		switch( $retention ){
			case '':
				// do nothing
				break;
			case self::RETENTION__OFF:
				$deleteFilePaths = array_slice(array_keys($allVersions),1); // remove from 3
				break;
			case self::RETENTION__LAST3:
				$deleteFilePaths = array_slice(array_keys($allVersions),3); // remove from 3
				break;
			case self::RETENTION__LAST7:
				$deleteFilePaths = array_slice(array_keys($allVersions),7);
				break;
			default:
				throw new RuntimeException('unknown retention strategy', 400);
		}
		foreach( $deleteFilePaths as $deleteFilePath ){
			$this->markFileDeleted($baseFilePath.'___'.$deleteFilePath);
		}
	}

	/**
	 * returns the result of "du storeDir" in bytes
	 * this is linux dependent, if want it more flexible, make it ;-)
	 * @throws RuntimeException
	 * @return int
	 */
	protected function getDirectorySize(){
		$command = '/usr/bin/du -bs '.escapeshellarg($this->config['storeDir']);
		exec($command, $output, $status);
		if( $status!==0 OR count($output)!=1 ){
			throw new RuntimeException('du failed with status: '.$status);
		}
		$size = intval(reset($output));
		return $size;
	}

	/**
	 * @param string $filePath
	 * @return bool
	 */
	protected function markFileDeleted($filePath){
		$result = false;
		if( file_exists($filePath) AND filemtime($filePath)!=self::$markDeletedTimestamp ){
			$result = touch($filePath, self::$markDeletedTimestamp) ;
		}
		return $result;
	}

	/**
	 * @param string $filePath
	 * @return array
	 */
	protected function getFileInfo($filePath){
		list($fileName, $version) = $this->extractVersionFromFullFileName($filePath);
		$fileTimestamp = filemtime($filePath);
		$info = [
			'name' => $fileName,
			'version' => $version,
			'sha1' => sha1_file($filePath), // ja sollte das selbe wie version sein, das bestätigt aber, das das file noch physisch korrekt ist
			'size' => filesize($filePath),
			'timestamp' => $fileTimestamp,
			'dateTime' => date('Y-m-d H:i:s', $fileTimestamp),
		];
		return $info;
	}

	/**
	 * @param string $sha1
	 * @throws RuntimeException
	 * @return string
	 */
	protected function getFilePath($sha1=''){
		$filePath = '';
		$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
		$fileName = basename($path);
		if( ltrim($path,DIRECTORY_SEPARATOR)!=$fileName ){
			throw new RuntimeException('a path is not allowed! use server.com/file.name', 400);
		}
		$allowedChars = '1234567890abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
		$allowedChars.= '-_.';
		if( preg_match('~[^'.preg_quote($allowedChars, '~').']~', $fileName) ){
			throw new RuntimeException('filename must only use these chars: '.$allowedChars, 400);
		}
		if( $fileName!='' ){
			$version = '';
			if( $sha1 ){
				$version = $sha1;
			}elseif( !empty($_REQUEST['version']) ){
				if( !ctype_alnum($_REQUEST['version']) ){
					throw new RuntimeException('invalid version', 400);
				}
				$version = $_REQUEST['version'];
			}
			// get split dir
			$fileNameMd5 = md5($fileName);
			$fileDir = $this->config['storeDir'].substr($fileNameMd5,-1,1).DIRECTORY_SEPARATOR.substr($fileNameMd5,-2,1).DIRECTORY_SEPARATOR;

			if( !$version ){ // get the newest version
				$version = reset(array_keys($this->getAllVersions($fileDir.$fileName)));
				if( !$version ){
					throw new RuntimeException('no version of file not found', 404);
				}
			}
			$filePath.= $fileDir.$fileName.'___'.$version;
		}
		// if we not create a new file, it must exists
		if( $sha1=='' AND $filePath AND !file_exists($filePath) ){
			throw new RuntimeException('File not found! '.$filePath, 404);
		}

		return $filePath;
	}

	/**
	 * @param string $filePathWithoutVersion
	 * @param bool $includeDeleted
	 * @return array|int
	 */
	protected function getAllVersions($filePathWithoutVersion, $includeDeleted=false){
		$versions = [];
		foreach(glob($filePathWithoutVersion.'___*') as $entryFileName) {
			/** @noinspection PhpUnusedLocalVariableInspection */
			list($fileName, $version) = $this->extractVersionFromFullFileName($entryFileName);
			$fileTimestamp = filemtime($entryFileName);
			if( $includeDeleted OR $fileTimestamp!=self::$markDeletedTimestamp ){
				$versions[$version] = $fileTimestamp;
			}
		}
		arsort($versions); // order by newest version
		return $versions;
	}

	/**
	 * $fullFileName - fileName or filePath with Version error.log___234687683724...
	 * @param string $fullFileName
	 * @return string[]
	 * @throws RuntimeException
	 */
	protected function extractVersionFromFullFileName($fullFileName){
		if( preg_match('~^(.*)___(\w+)$~', basename($fullFileName), $matches) ){
			$fileName = $matches[1];
			$version = $matches[2];
		}else{
			throw new RuntimeException('unexpected fileName:'.$fullFileName);
		}
		return [$fileName, $version];
	}

	/**
	 * get the own address
	 * @throws RuntimeException
	 */
	protected function getOwnHostPort(){
		if( empty($this->config['hostPort']) ){
			throw new RuntimeException('hostPort in config is missing, can not perform remote operation, please set "hostPort" to a reachable value (ric.example.com:3333)');
		}
		return $this->config['hostPort'];
	}

	/**
	 * return same regEx if valid
	 * or throws Exception if not valid
	 * @param string $regEx
	 * @return string
	 * @throws RuntimeException
	 */
	static protected function validateRegex($regEx){
		// check is valid regex
		set_error_handler('Ric_Server_ErrorHandler_Static::handleError');
		Ric_Server_ErrorHandler_Static::reset();
		preg_match($regEx,'');
		$error = Ric_Server_ErrorHandler_Static::$errstr;
		restore_error_handler();
		if( $error ){
			throw new RuntimeException('not a valid regex: '.$error, 400);
		}
		return $regEx;
	}

}

class Ric_Server_ErrorHandler_Static {

	static public  $errno = 0;
	static public  $errstr = '';
	static public  $errfile = '';
	static public  $errline = '';
	static public  $errcontext = array();

	/**
	 * @param int $errno
	 * @param string $errstr
	 * @param string $errfile
	 * @param int $errline
	 * @param array $errcontext
	 * @return bool
	 */
	static public function handleError($errno, $errstr, $errfile, $errline, $errcontext){
		self::$errno = $errno;
		self::$errstr = $errstr;
		self::$errfile = $errfile;
		self::$errline = $errline;
		self::$errcontext = $errcontext;
		return true;
	}

	/**
	 * reset last error
	 */
	static public function reset(){
		self::$errno = 0;
		self::$errstr = '';
		self::$errfile = '';
		self::$errline = 0;
		self::$errcontext = array();
	}
}