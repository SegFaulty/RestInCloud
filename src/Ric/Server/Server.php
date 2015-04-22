<?php

# todo admin dump
# todo admin restore
# todo sync command push to all server
# todo pull command dump, local restore

# todo admin flush

# todo auth http basic auth
# todo clicommands -> handleCli($argv)
# todo use H::getIKS
# todo use H::getPR
# todo split list in files and versions
# todo admin joinCluster $serverIps / get servers from server, add to all servers, dump remote restore local
# todo admin leaveCluster
# todo dockerize
# todo help as mark down
# todo apache sapi
# todo php client
# todo php shell script
# todo shell script
# todo think about configstorage also download immer der neusten version, sollte ja einfach gehen (mit 304er)

# reader/writer/admin roles
# to github
# find a nice name
# rename flush to purge
# get replica count
# noSync
# synchronize put and post
# admin removeServer added
# admin commands require user admin or show less or block
# renamed usage to info

require_once __DIR__.'/../Rest/Client.php';
date_default_timezone_set('Europe/Berlin');


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
	ini_set("error_log", '/home/www/coldStoreErrors.log');

	$coldStore = new Ric_Server_Server(H::getIKS($_ENV, 'Ric_config', './config.json'));
	$coldStore->handleRequest();
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
				.'php -S 0.0.0.0:3070 '.__FILE__."\n"
				.' with config :  '."\n"
				.' Ric_config=./config.json php -d variables_order=EGPCS -S 0.0.0.0:3070 -t /path/to/docroot '.__FILE__."\n"
				.'   OR   '."\n"
				.'php '.__FILE__.' purge /path/to/storeDir {maxTimestamp}'."\n"
				.'  to purge all files marked for deletion (with fileMtime < maxTimestamp)'."\n"
			);
	}
	return 1;
}

die('unsupported php_sapi_name');


/**
 * Class Ric_Server_Server
 */
class Ric_Server_Server {

	static $markDeletedTimestamp = 1422222222; // 2015-01-25 22:43:42

	protected $defaultConfig = [
		'storeDir' => '/tmp/',
		'quota' => 0,
		'servers' => [],
		'adminName' => 'admin',
		'adminPass' => 'admin',
		'writerName' => 'writer',
		'writerPass' => 'writer',
		'readerName' => '',
		'readerPass' => '',
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
			}elseif( $_SERVER['REQUEST_METHOD']=='GET' ){
				$this->handleGetRequest();
			}elseif( $_SERVER['REQUEST_METHOD']=='POST' ){
				$this->handlePostRequest();
			}else{
				throw new RuntimeException('unsupported http-method', 400);
			}
		}catch(Exception $e){
			if( $e->getCode()===400 ){
				header('HTTP/1.1 400 Bad Request', true, 400);
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
		$coldStore = new Ric_Server_Server($storeDir);
		echo H::json($coldStore->purge($maxTimestamp));
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
		stream_copy_to_stream($putData, $fp);
		fclose($fp);
		fclose($putData);

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
	 * handle POST, file refresh
	 */
	protected function handlePostRequest(){
		$this->auth('writer');
		$result = '0';
		$sha1 = H::getRP('sha1');
		$retention = H::getRP('retention', '');
		$timestamp = H::getRP('timestamp', time());
		$noSync = (bool) H::getRP('noSync');

		if( $sha1=='' ){
			throw new RuntimeException('?sha1=1342.. is required', 400);
		}

		$filePath = $this->getFilePath($_REQUEST['sha1']);
		if( file_exists($filePath) ){
			$syncResult = $this->syncFile($filePath, $timestamp, $retention, $noSync);
			$this->executeRetention($filePath, $retention);
			if( $syncResult=='' ){
				$result = '1';
			}
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
					$serverUrl = $this->getServerUrl($server);
					// try to refresh file
					$url = $serverUrl.$fileName.'?sha1='.sha1_file($filePath).'&timestamp='.$timestamp.'&retention='.$retention.'&noSync=1';
					$response = Ric_Rest_Client::post($url);
					if( trim($response)!='1' ){
						// refresh failed, upload
						$url = $serverUrl.$fileName.'?timestamp='.$timestamp.'&retention='.$retention.'&noSync=1';
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
			if( $action=='list' ){
				$this->actionList();
			}elseif( $action=='help' ){
				$this->actionHelp();
			}elseif( $action=='info' ){
				$this->actionInfo();
			}elseif( $action=='addServer' AND $this->auth('admin') ){
				$this->actionAddServer();
			}elseif( $action=='removeServer' AND $this->auth('admin') ){
				$this->actionRemoveServer();
			}else{
				throw new RuntimeException('unknown action', 400);
			}
		}elseif( $action=='delete' AND $this->auth('writer') ){
			$this->actionDelete();
		}elseif( $action=='size' ){
			echo filesize($this->getFilePath());
		}elseif( $action=='verify' ){
			$this->actionVerify();
		}elseif( $action=='list' ){
			$this->actionList();
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
	 * @param string $user
	 * @param bool $isRequired
	 * @return bool
	 * @throws RuntimeException
	 */
	protected function auth($user='reader', $isRequired=true){
		$isAuth = false;

		$userRole = 'guest';

		// todo implement basic auth
		if( H::getRP('reader') OR $this->config['readerName']!='' ){
			$userRole = 'reader';
		}
		if( H::getRP('writer') OR $this->config['writerName']!='' ){
			$userRole = 'writer';
		}
		if( H::getRP('admin') ){
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
			throw new RuntimeException('login needed', 400);
		}
		return $isAuth;
	}

	/**
	 * @throws RuntimeException
	 */
	protected function actionAddServer(){
		$server = H::getRP('addServer');

		$info = json_decode(Ric_Rest_Client::get($this->getServerUrl($server), ['info'=>1]), true);
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
	 * build url for remote server
	 * @param string $server
	 * @param string $user
	 * @return string
	 */
	protected function getServerUrl($server, $user='reader'){
		$serverUrl = 'http://';
		switch( $user ){
			case 'admin':
				$name = $this->config['adminName'];
				$pass = $this->config['adminPass'];
				break;
			case 'writer':
				$name = $this->config['writerName'];
				$pass = $this->config['writerPass'];
				break;
			default:
				$name = $this->config['readerName'];
				$pass = $this->config['readerPass'];
		}
		if( $name!='' ){
			$serverUrl.= $name;
			if( $pass!='' ){
				$serverUrl.= $pass;
			}
			$serverUrl.= '@';
		}
		$serverUrl.= $server.'/';
		return $serverUrl;
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
	 * mark one or all versions of the File as deleted
	 */
	protected function actionDelete(){
		$filePath = $this->getFilePath();
		$filesDeleted = 0;
		if( !empty($_REQUEST['version']) ){
			$filesDeleted+= $this->markFileDeleted($filePath);
		}else{
			$baseFilePath = preg_replace('~___\w+$~', '', $filePath);
			foreach( $this->getAllVersions($baseFilePath) as $version=>$timestamp){
				$filesDeleted+= $this->markFileDeleted($baseFilePath.'___'.$version);
			}
		}
		header('Content-Type: application/json');
		echo H::json(['fileDeleted' => $filesDeleted]);
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
		$minReplicas = H::getRP('minReplicas'); // if parameter omitted, don't check replicas!!!! or deadlock

		$fileInfo = $this->getFileInfo($filePath);
		$fileInfo['replicas'] = false;
		if( $minReplicas!==null ){
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

		foreach( $this->config['servers'] as $server ){
			try{
				$serverUrl = $this->getServerUrl($server);
				// verify file
				$url = $serverUrl.$fileName.'?verify&version='.$version;
				$response = json_decode(Ric_Rest_Client::get($url), true);
				if( isset($response['status']) AND $response['status']=='OK' ){
					$replicas++;
				}
			}catch(Exception $e){
				// unwichtig
			}
		}
		return $replicas;
	}

	/**
	 * list files or version of file
	 * @throws RuntimeException
	 */
	protected function actionList(){
		$pattern = (isset($_REQUEST['pattern']) ? self::validateRegex($_REQUEST['pattern']) : '' );
		$showDeleted = isset($_REQUEST['showDeleted']);
		$start = (isset($_REQUEST['start']) ? $_REQUEST['start'] : 0 );
		$limit = (isset($_REQUEST['limit']) ? min(10000, $_REQUEST['limit']) : 1000 );

		$filePath = $this->getFilePath();

		$lines = [];

		// if we ?list on a fileName we return all versions
		if( $filePath ){
			if($pattern!='' OR $start>0 ){
				throw new RuntimeException('pattern and start not supported for version list', 400);
			}
			$baseFilePath = preg_replace('~___\w+$~', '', $filePath);
			$index = 1;
			foreach( $this->getAllVersions($baseFilePath, $showDeleted) as $version=>$timeStamp ){
				$filePath = $baseFilePath.'___'.$version;
				$lines[] = ['index' => $index++] + $this->getFileInfo($filePath);
				if( $limit>0 AND $limit<$index ){
					break;
				}
			}
		}else{
			$dirIterator = new RecursiveDirectoryIterator($this->config['storeDir']);
			$iterator = new RecursiveIteratorIterator($dirIterator, RecursiveIteratorIterator::SELF_FIRST);
			$lines = array();
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
						$lines[] = ['index' => $index] + $fileInfo;
						if( count($lines)>=$limit ){
							break;
						}
					}
				}
			}
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
		$lines = ($_REQUEST['head']>0? $_REQUEST['head'] : 10);
		while($lines-- AND ($line = gzgets($fp, 100000))){
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
		$regex = self::validateRegex($_REQUEST['grep']);
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
		header('Content-Type: application/json');
		echo H::json($info);
	}
	/**
	 * help
	 */
	protected function actionHelp(){
		$help = <<< EOT
 * GET http://my.coldstore.server.de/?help - show this help
 * GET http://my.coldstore.server.de/?list - list all files ... &pattern=~regEx~i&limit=100&start=100&showDeleted (ordered by random!)
 * GET http://my.coldstore.server.de/?info - show server infos (and quota if set)

 * PUT http://my.coldstore.server.de/error.log - upload a file to the store
   - use ?timestamp=1422653.. to set correct modificationTime [default:requestTime]
   - use &retention=last3 to select the backup retention strategy [default:last3]
   - use &noSync to suppress syncronisation to replication servers (used for internal sync)
   - retension strategies (versions sorted by timestamp):
{retentionList}
   - with curl:
     curl -X PUT --upload /home/www/phperror.log http://my.coldstore.server.de/error.log
     curl -X PUT --upload "/home/www/phperror.log" "http://my.coldstore.server.de/error.log&retention=last7&timestamp=1429628531"

 * POST http://my.coldstore.server.de/error.log?sha1=23423ef3d..&timestamp=1422653.. - check and refresh a file
   - use &noSync to suppress syncronisation to replication servers (used for internal sync)
   - check if version exists and updates timestamp, no need to upload the same version
   - returns 1 if version was updated, 0 if version not exists

 * GET http://my.coldstore.server.de/error.log - download a file (etag and lastmodified supported)
 * GET http://my.coldstore.server.de/error.log?version=13445afe23423423 - version selects a specific version, if omitted the latest version is assumed
 * GET http://my.coldstore.server.de/error.log?list - show all (or &limit) versions for this file; (ordered by latest); &showDeleted to include files marked for deletion
 * GET http://my.coldstore.server.de/error.log?delete - delete a file !! Attention if version is omitted, ALL Versions will be deleted (Files are marked for deletion, purge will delete them)
 * GET http://my.coldstore.server.de/error.log?head - show first (10) lines of file
 * GET http://my.coldstore.server.de/error.log?head=20 - show first n lines of the file
 * GET http://my.coldstore.server.de/error.log?size - return the filesize
 * GET http://my.coldstore.server.de/error.log?grep=EAN:.*\d+501 - scan the file for this (regex) pattern
 * GET http://my.coldstore.server.de/error.log?verify&sha=1234ef23&minSize=40000&minReplicas=2&minTimestamp=14234234
    - verify that the file (1) exists, (2) sha1, (3) size >40k [default:1], (4) fileTime>=minTimestamp [default:8d], (5) min 2 replicas (3 files) [default:0]
    - returns json result with status: OK/WARNING/CRITICAL, a msg and fileInfo

 * check php Server.php for commandline (purge)

 admin Commands:
 * addServer=s1.cs.io:3723 - add Server to local list,
 * removeServer=s1.cs.io:3723 - remove Server from local list
 * removeServer=all - remove all Servers from local list

EOT;
		$help = str_replace('my.coldstore.server.de', $_SERVER['HTTP_HOST'], $help);
		$retentions = '';
		$retentions.= '     '.self::RETENTION__OFF.' : keep only the last version'.PHP_EOL;
		$retentions.= '     '.self::RETENTION__LAST3.' : keep last 3 versions'.PHP_EOL;
		$retentions.= '     '.self::RETENTION__LAST7.' : keep last 7 versions'.PHP_EOL;
		$retentions.= '     '.self::RETENTION__3L7D4W12M.' : keep last 3 versions then last of 7 days, 4 weeks, 12 month (max 23 Versions)'.PHP_EOL;
		$help = str_replace('{retentionList}', $retentions, $help);
		echo '<pre>'.htmlentities($help).'</pre>';
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
					throw new RuntimeException('file not found');
				}
			}
			$filePath.= $fileDir.$fileName.'___'.$version;
		}
		// if we not create a new file, it must exists
		if( $sha1=='' AND $filePath AND !file_exists($filePath) ){
			throw new RuntimeException('File not found!'.$filePath, 404);
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