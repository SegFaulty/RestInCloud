<?php

/**
 * Class Ric_Server_Server
 */
class Ric_Server_Server {

	const VERSION = '0.8.0'; // dont forget to change the client(s) version | if you break api backward compatibility inc the major version | all clients will fail until they updated

	/**
	 * @var Ric_Server_ConfigManager
	 */
	protected $configManager;

	/**
	 * @var Ric_Server_File_Manager
	 */
	protected $fileManager;

	/**
	 * @var Ric_Server_Cluster_Manager
	 */
	protected $clusterManager;

	/**
	 * construct
	 * @param Ric_Server_ConfigManager $configManager
	 * @throws RuntimeException
	 */
	public function __construct($configManager){
		$this->configManager = $configManager;
		$config = $this->configManager->getConfig();
		if( empty($config) ){
			throw new RuntimeException('No config found');
		}
		$this->fileManager = new Ric_Server_File_Manager($this->configManager->getValue('storeDir'));
		$this->clusterManager = new Ric_Server_Cluster_Manager($this->configManager);

		// set tmp dir to store dir, to create the uploaded files on the store drive (to avoid copying to final destination, they will be renamed)
		$this->setTmpDir($this->configManager->getValue('storeDir'));

		// check server id, set if empty, the serverId is a randomHexString for every instanu to prevent a server add its own because of different host names
		if( $this->configManager->getValue('serverId')=='' ){
			$this->configManager->setRuntimeValue('serverId', substr(md5(uniqid('', true)), 0, 8));
		}
	}

	/**
	 * handle PUT
	 * @param string $tmpFilePath
	 * @param string $fileName
	 * @param string $retention @see Ric_Server_Definition::RETENTION__*
	 * @param int $timestamp
	 * @param boolean $noSync
	 * @return Ric_Server_Response
	 * @throws RuntimeException
	 */
	public function saveFileInCloud($tmpFilePath, $fileName, $retention, $timestamp, $noSync){
		$result = [
				'status' => 'OK',
				'fileName' => $fileName,
				'size' => filesize($tmpFilePath),
		];

		$version = $this->fileManager->storeFile($fileName, $tmpFilePath);
		$result['version'] = $version;

		// check quota
		if( $this->configManager->getValue('quota')>0 ){
			if( $this->fileManager->getDirectorySize()>$this->configManager->getValue('quota') * 1024 * 1024 ){
				$filePath = $this->fileManager->getFilePath($fileName, $version);
				unlink($filePath);
				throw new RuntimeException('Quota exceeded!', 507);
			}
		}

		// set timestamp
		$filePath = $this->fileManager->getFilePath($fileName, $version);
		$this->fileManager->updateTimestamp($fileName, $version, $timestamp);
		$result['timestamp'] = $timestamp;
		// replicate
		if( !$noSync ){
			$syncResult = $this->clusterManager->syncFile($fileName, $filePath, $retention);
			if( $syncResult!='' ){
				throw new RuntimeException('sync uploaded file failed: '.$syncResult.' (file is locally saved!)');
			}
		}
		# delete outimed version
		$result['versions'] = $this->executeRetention($fileName, $retention);

		$response = new Ric_Server_Response();
		$response->addHeader('HTTP/1.1 201 Created', 201);
		$response->setResult($result);
		return $response;
	}

	/**
	 * check and refresh a file with a post request
	 * returns status OK if file is update in the whole cluster
	 * return status not found if not found on local server, failed if sync to other servers failed
	 *
	 * @param string $fileName
	 * @param string $version
	 * @param int $timestamp
	 * @param boolean $noSync
	 * @return Ric_Server_Response
	 */
	public function refreshFile($fileName, $version, $timestamp, $noSync){
		$result = ['status' => 'not found'];

		$filePath = $this->fileManager->getFilePath($fileName, $version);
		if( file_exists($filePath) ){
			$this->fileManager->updateTimestamp($fileName, $version, $timestamp);
			if( !$noSync ){
				$syncResult = $this->clusterManager->syncFile($fileName, $filePath, $retention = Ric_Server_Definition::RETENTION__ALL);
				if( $syncResult!='' ){
					throw new RuntimeException('sync file failed: '.$syncResult);
				}
			}
			$result['status'] = 'OK';
		}
		$response = new Ric_Server_Response();
		$response->setResult($result);
		return $response;
	}

	/**
	 * @param string $server
	 * @param string $fileName
	 * @param string $version
	 * @return Ric_Server_Response
	 */
	public function pushFileToServer($server, $fileName, $version){
		if( $version=='' ){
			throw new RuntimeException('version missed (you have to select a specific version for push)', 400);
		}
		$filePath = $this->fileManager->getFilePath($fileName, $version);
		$syncResult = $this->clusterManager->pushFileToServer($server, $fileName, $filePath, Ric_Server_Definition::RETENTION__ALL);
		if( $syncResult!='' ){
			throw new RuntimeException('pushFileToServer failed: '.$syncResult);
		}
		$result['status'] = 'OK';
		$response = new Ric_Server_Response();
		$response->setResult($result);
		return $response;
	}

	/**
	 * mark one or all versions of the File as deleted
	 * @param string $fileName
	 * @param string $version
	 * @param bool $noSync
	 * @throws RuntimeException
	 * @return Ric_Server_Response
	 */
	public function deleteFile($fileName, $version, $noSync = false){
		$deleteCount = $this->fileManager->deleteFile($fileName, $version);
		if( !$noSync ){
			$deleteCount += $this->clusterManager->deleteFile($fileName, $version, $error);
			if( $error ){
				throw new RuntimeException('delete file from cluster failed: '.$error.' files deleted: '.$deleteCount, 500);
			}
		}
		$response = new Ric_Server_Response();
		$result = [
				'status'       => 'OK',
				'filesDeleted' => $deleteCount,
		];
		$response->setResult($result);
		return $response;
	}

	/**
	 * list files or version of file
	 * @param string $fileName
	 * @param string $version
	 * @param string $sha1
	 * @param int $minSize
	 * @param int $minTimestamp
	 * @param int $minReplicas
	 * @return Ric_Server_Response
	 */
	public function checkFile($fileName, $version, $sha1, $minSize, $minTimestamp, $minReplicas = null){
		$result = [];
		$result['status'] = 'OK';
		$result['msg'] = '';

		if( $minReplicas==null ){
			$minReplicas = max(1, count($this->configManager->getValue('servers')) - 1); // min 1, or 1 invalid of current servers
		}


		$fileInfo = $this->fileManager->getFileInfo($fileName, $version);
		$infos = [
				'name'      => $fileInfo->getName(),
				'version'   => $fileInfo->getVersion(),
				'dateTime'  => $fileInfo->getDateTime(),
				'timestamp' => $fileInfo->getTimestamp(),
				'size'      => $fileInfo->getSize(),
		];
		$filePath = $this->fileManager->getFilePath($fileName, $version);
		$infos['sha1'] = sha1_file($filePath);

		$infos['replicas'] = false;
		if( $minReplicas>0 ){
			$infos['replicas'] = $this->clusterManager->getReplicaCount($fileName, $version, $sha1);
		}
		if( $sha1!='' AND $infos['sha1']!=$sha1 ){
			$result['status'] = 'CRITICAL';
			$result['msg'] .= 'unmatched sha1'.PHP_EOL;
		}
		if( $infos['version']!=$infos['sha1'] ){
			$result['status'] = 'CRITICAL';
			$result['msg'] .= 'unmatching version and sha1 file corrupt'.PHP_EOL;
		}
		if( $infos['size']<$minSize ){
			$result['status'] = 'CRITICAL';
			$result['msg'] .= 'size less then expected ('.$infos['size'].'/'.$minSize.')'.PHP_EOL;
		}
		if( $minTimestamp>0 AND $infos['timestamp']<$minTimestamp ){
			$result['status'] = 'CRITICAL';
			$result['msg'] .= 'file is outdated ('.$infos['timestamp'].'/'.$minTimestamp.')'.PHP_EOL;
		}
		if( $minReplicas>0 AND $infos['replicas']<$minReplicas ){
			$result['status'] = 'CRITICAL';
			// wenn wir mindestens replikat haben und nur eins fehlt, dann warning (das wird mutmasslich gerade gelöst)
			if( $infos['replicas']>0 AND $infos['replicas']>=$minReplicas - 1 ){
				$result['status'] = 'WARNING';
			}
			$result['msg'] .= 'not enough replicas ('.$infos['replicas'].'/'.$minReplicas.')'.PHP_EOL;
		}
		$result['msg'] = trim($result['msg']);
		$result['fileInfo'] = $infos;
		$response = new Ric_Server_Response();
		$response->setResult($result);
		return $response;
	}

	/**
	 * list files
	 * @param string $pattern
	 * @param int $start
	 * @param int $limit
	 * @return Ric_Server_Response
	 */
	public function listFileNames($pattern, $start, $limit){
		$fileNames = $this->fileManager->getFileNamesForPattern($pattern, $start, $limit);
		$response = new Ric_Server_Response();
		$response->setResult($fileNames);
		return $response;
	}

	/**
	 * list versions of file
	 * @param string $fileName
	 * @param int $limit
	 * @return Ric_Server_Response
	 */
	public function listVersions($fileName, $limit){
		$lines = [];
		$index = -1;
		foreach( $this->fileManager->getAllVersions($fileName) as $version => $timeStamp ){
			$index++;
			if( $limit<=$index ){
				break;
			}
			$fileInfo = $this->fileManager->getFileInfo($fileName, $version);
			$lines[] = [
					'index'     => $index,
					'name'      => $fileInfo->getName(),
					'version'   => $fileInfo->getVersion(),
					'dateTime'  => $fileInfo->getDateTime(),
					'timestamp' => $fileInfo->getTimestamp(),
					'size'      => $fileInfo->getSize(),
			];

		}
		$response = new Ric_Server_Response();
		$response->setResult($lines);
		return $response;
	}

	/**
	 * get server info
	 * @param bool $isAdmin
	 * @return Ric_Server_Response
	 */
	public function showServerInfo($isAdmin = false){
		$response = new Ric_Server_Response();
		$response->setResult($this->buildInfo($isAdmin));
		return $response;
	}

	/**
	 * get server info
	 * @param bool $isAdmin
	 * @return array
	 */
	protected function buildInfo($isAdmin = false){
		$info['serverId'] = $this->configManager->getValue('serverId');
		$info['serverVersion'] = self::VERSION;
		$info['serverTimestamp'] = time();
		if( $isAdmin ){ // only for admins
			$directorySize = $this->fileManager->getDirectorySize();
			$directorySizeMb = ceil($directorySize / 1024 / 1024); // IN MB
			$info['usageByte'] = $directorySize;
			$info['usage'] = $directorySizeMb;
			$info['quota'] = $this->configManager->getValue('quota');
			if( $this->configManager->getValue('quota')>0 ){
				$info['quotaLevel'] = ceil($directorySizeMb / $this->configManager->getValue('quota') * 100);
				$info['quotaFreeLevel'] = max(0, min(100, 100 - ceil($directorySizeMb / $this->configManager->getValue('quota') * 100)));
				$info['quotaFree'] = max(0, intval($this->configManager->getValue('quota') - $directorySizeMb));
			}
			$info['config'] = $this->configManager->getConfig();
			$info['runtimeConfig'] = false;
			if( file_exists($this->configManager->getValue('storeDir').'intern/config.json') ){
				$info['runtimeConfig'] = json_decode(file_get_contents($this->configManager->getValue('storeDir').'intern/config.json'), true);
			}
			$info['defaultConfig'] = $this->configManager->getDefaultConfig();
		}
		return $info;
	}

	/**
	 * check for all servers
	 * quota <85%; every server knowns every server
	 *
	 * get server info
	 * @param bool $isAdmin
	 * @return Ric_Server_Response
	 * @throws RuntimeException
	 */
	public function getHealthInfo($isAdmin = false){
		$criticalQuotaFreeLevel = 15;
		$status = 'OK';
		$msg = '';

		$serversFailures = [];
		$ownHostPort = $this->clusterManager->getOwnHostPort();
		$clusterServers = array_merge([$ownHostPort], $this->configManager->getValue('servers'));
		sort($clusterServers);
		// get serverInfos
		$serverInfos = [];
		$serverInfos[$ownHostPort] = $this->buildInfo(true);
		foreach( $this->configManager->getValue('servers') as $server ){
			try{
				$url = 'http://'.$server.'/?info&token='.$this->configManager->getValue('adminToken');
				$result = json_decode(Ric_Rest_Client::get($url), true);
				if( !array_key_exists('usageByte', $result) ){
					throw new RuntimeException('info failed');
				}
				$serverInfos[$server] = $result;
			}catch(Exception $e){
				$serversFailures[$server][] = $e->getMessage();
			}
		}
		// check serverInfos
		foreach( $serverInfos as $server => $serverInfo ){
			// check quota
			if( array_key_exists('quotaFreeLevel', $serverInfo) AND $serverInfo['quotaFreeLevel']<$criticalQuotaFreeLevel ){
				$serversFailures[$server][] = 'quotaFreeLevel:'.$serverInfo['quotaFreeLevel'].'%';
			}
			// check servers
			$expectedClusterServers = array_values(array_diff($clusterServers, [$server]));
			$existingServers = array_values($serverInfo['config']['servers']);
			rsort($expectedClusterServers);
			rsort($existingServers);
			if( $expectedClusterServers!=$existingServers ){
				$serversFailures[$server][] = 'unxpected clusterServer: '.join(',', $existingServers).' (expected: '.join(',', $expectedClusterServers).')';
			}
			// check versions
			if( $serverInfo['serverVersion']!==self::VERSION ){
				$serversFailures[$server][] = 'different serverVersion ['.$serverInfo['serverVersion'].'] at '.$server.' (my Version is : '.self::VERSION.')';
			}
		}
		// build quota info
		foreach( $serverInfos as $server => $serverInfo ){
			$msg .= $server.' '.($serverInfo['quota'] - $serverInfo['usage']).' MB free of '.$serverInfo['quota'].' MB '.' used '.$serverInfo['usage'].' MB ('.$serverInfo['quotaLevel'].'%)'.PHP_EOL;
		}
		// check failedServers
		if( !empty($serversFailures) ){
			$status = 'WARNING';
			$msg .= 'servers with failure: '.count($serversFailures).PHP_EOL;
			foreach( $serversFailures as $server => $serverError ){
				$msg .= $server.' '.implode('; ', $serverError).PHP_EOL;
			}
		}
		// check if cluster in critical state
		if( count($serverInfos)<2 OR count($serversFailures)>1 ){
			$status = 'CRITICAL';
			$msg .= 'replication critical! servers running: '.count($serverInfos).' remoteServer configured: '.count($this->configManager->getValue('servers')).PHP_EOL;
		}
		// ok servers:
		$msg .= 'servers ok: '.(count($this->configManager->getValue('servers')) + 1 - count($serversFailures)).PHP_EOL;

		$result = [
				'status' => $status,
		];
		if( $isAdmin ){
			$result['message'] = $msg;
		}
		$response = new Ric_Server_Response();
		$response->setResult($result);
		return $response;
	}

	/**
	 * help
	 * @return Ric_Server_Response
	 */
	public function getHelpInfo(){
		$helpString = '';
		// extract from README-md
		$readMePath = __DIR__.'/../../../README.md';
		if( file_exists($readMePath) and preg_match('~\n## Help(.*?)(\n## |$)~s', file_get_contents($readMePath), $matches) ){
			$helpString = $matches[1];
		}
		$helpString = str_replace('ric1.server', $_SERVER['HTTP_HOST'], $helpString);
		$retentions = '';
		$retentions .= '     '.Ric_Server_Definition::RETENTION__OFF.' : keep only the last version'.PHP_EOL;
		$retentions .= '     '.Ric_Server_Definition::RETENTION__AUTO.' : size dependend:  <10MB = 3l7d4w12m  | <1GB 3l4w  | default 3l'.PHP_EOL;
		$retentions .= '     '.Ric_Server_Definition::RETENTION__LAST3.' : keep last 3 versions'.PHP_EOL;
		$retentions .= '     '.Ric_Server_Definition::RETENTION__LAST7.' : keep last 7 versions'.PHP_EOL;
		$retentions .= '     '.Ric_Server_Definition::RETENTION__3L7D4W12M.' : keep last 3 versions then last of 7 days, 4 weeks, 12 month (max 23 Versions)'.PHP_EOL;
		$helpString = str_replace('{retentionList}', $retentions, $helpString);

		$response = new Ric_Server_Response();
		$response->addOutput('<pre>'.htmlentities($helpString).'</pre>');
		return $response;
	}

	/**
	 * send an existing file
	 * @param string $fileName
	 * @param string $fileVersion
	 * @return Ric_Server_Response
	 * @throws RuntimeException
	 */
	public function sendFile($fileName, $fileVersion){
		$fileInfo = $this->fileManager->getFileInfo($fileName, $fileVersion);
		if( !$fileInfo ){
			throw new RuntimeException('File not found! '.$fileName.' ['.$fileVersion.']', 404);
		}

		$response = new Ric_Server_Response();
		$lastModified = gmdate('D, d M Y H:i:s \G\M\T', $fileInfo->getTimestamp());
		$eTag = $fileInfo->getVersion();

		// 304er support
		$ifMod = isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) ? $_SERVER['HTTP_IF_MODIFIED_SINCE']==$lastModified : null;
		$ifTag = isset($_SERVER['HTTP_IF_NONE_MATCH']) ? $_SERVER['HTTP_IF_NONE_MATCH']==$eTag : null;
		if( ($ifMod || $ifTag) && ($ifMod!==false && $ifTag!==false) ){
			$response->addHeader('HTTP/1.0 304 Not Modified');
		}else{
			$response->addHeader('Content-Type: application/octet-stream');
			$response->addHeader('Content-Disposition: attachment; filename="'.$fileName.'"');
			$response->addHeader('Content-Length: '.$fileInfo->getSize());
			$response->addHeader('Last-Modified:'.$lastModified);
			$response->addHeader('ETag: '.$eTag);
			$filePath = $this->fileManager->getFilePath($fileName, $fileVersion);
			$response->setOutputFilePath($filePath);
		}
		return $response;
	}

	/***************/
	/*** CLUSTER ***/

	/**
	 * @param string $server
	 * @return Ric_Server_Response
	 * @throws RuntimeException
	 */
	public function addServer($server){
		$this->clusterManager->addServer($server);
		$response = new Ric_Server_Response();
		$response->setResult(['status' => 'OK']);
		return $response;
	}

	/**
	 * remove selected or "all" servers
	 * @param $server
	 * @return Ric_Server_Response
	 */
	public function removeServer($server){
		$this->clusterManager->removeServer($server);

		$response = new Ric_Server_Response();
		$response->setResult(['status' => 'OK']);
		return $response;
	}

	/**
	 * join a existing cluster
	 * get all servers of the given clusterMember an send an addServer to all
	 * if it fails, the cluster is in inconsistent state, send leaveCluster command
	 * @param string $server
	 * @return Ric_Server_Response
	 * @throws RuntimeException
	 */
	public function joinCluster($server){
		$this->clusterManager->joinCluster($server);

		$response = new Ric_Server_Response();
		$response->setResult(['status' => 'OK']);
		return $response;
	}

	/**
	 * leaving a cluster
	 * send removeServer to all servers
	 * if it fails, the cluster is in inconsistent state, send leaveCluster command
	 * @throws RuntimeException
	 */
	public function leaveCluster(){
		$this->clusterManager->leaveCluster();

		$response = new Ric_Server_Response();
		$response->setResult(['status' => 'OK']);
		return $response;
	}

	/**
	 * remove a server from the cluster
	 * send removeServer to all servers
	 * @param string $server
	 * @return Ric_Server_Response
	 * @throws RuntimeException
	 */
	public function removeFromCluster($server){
		$this->clusterManager->removeFromCluster($server);

		$response = new Ric_Server_Response();
		$response->setResult(['status' => 'OK']);
		return $response;
	}

	/*** CLUSTER ***/
	/***************/

	/**
	 * returns existing versions count
	 * @param string $fileName
	 * @param string $retention
	 * @return int
	 * @throws RuntimeException
	 */
	protected function executeRetention($fileName, $retention){
		$allVersions = $this->fileManager->getAllVersions($fileName);
//      Ric_Server_Helper_RetentionCalculator::setDebug(true);
		$wantedVersions = Ric_Server_Helper_RetentionCalculator::getVersionsForRetentionString($allVersions, $retention);
		$unwantedVersions = array_diff(array_keys($allVersions), array_values($wantedVersions));
		if( count($unwantedVersions)>=count($allVersions) ){
			throw new RuntimeException('count($unwantedVersions)>=$allVersions this must be really really wrong! retention:'.$retention);
		}
		// ensure we are not deleting the latest/current/newest version
		arsort($allVersions);
		$currentVersion = key($allVersions);
		foreach( $unwantedVersions as $version ){
			if( $version==$currentVersion ){
				throw new RuntimeException('whoa we will delete the newest version this must be really really wrong! retention:'.$retention);
			}
			$this->fileManager->deleteFile($fileName, $version);
		}
		return count($wantedVersions);
	}

	##### TMP File handling ########
	private $tmpFilePaths = [];
	private $tmpFileDir = ''; // use system default

	/**
	 * set the tmpDir to writable and secure location
	 * @param $dir
	 */
	private function setTmpDir($dir){
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