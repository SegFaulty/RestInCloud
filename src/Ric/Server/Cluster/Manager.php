<?php

/**
 * @todo
 * syncfile
 * verify
 *
 * (dump, restore)
 */
class Ric_Server_Cluster_Manager {

	/**
	 * @var Ric_Server_ConfigManager
	 */
	protected $configManager;

	/**
	 * Ric_Server_Cluster_Manager constructor.
	 * @param Ric_Server_ConfigManager $configManager
	 */
	public function __construct(Ric_Server_ConfigManager $configManager){
		$this->configManager = $configManager;
	}

	/**
	 * @param string $server
	 * @throws RuntimeException
	 */
	public function addServer($server){
		$response = Ric_Rest_Client::get('http://'.$server.'/', ['info' => 1, 'token' => $this->configManager->getValue('readerToken')]);
		$info = json_decode($response, true);
		if( !H::getIKS($info, 'serverTimestamp') ){
			throw new RuntimeException('server is not responding properly', 400);
		}
		$remoteServerId = $info['serverId'];
		if( $remoteServerId=='' ){
			throw new RuntimeException('server ('.$server.')has no serverId', 400);
		}
		$myServerId = $this->configManager->getValue('serverId');
		if( $remoteServerId==$myServerId ){
			throw new RuntimeException('whoaa, the server you want to add ('.$server.') is the same as me('.$this->getOwnHostPort().') because we both have the same serverId ('.$myServerId.')', 400);
		}
		$servers = $this->configManager->getValue('servers');
		if( !in_array($server, $servers) ){
			$servers[] = $server;
			$this->configManager->setRuntimeValue('servers', $servers);
		}else{
			// seerver is already added, ignore silently
		}
	}

	/**
	 * join a existing cluster
	 * get all servers of the given clusterMember an send an addServer to all
	 * if it fails, the cluster is in inconsistent state, send leaveCluster command
	 * @param string $server
	 * @throws RuntimeException
	 */
	public function joinCluster($server){
		$ownServer = $this->getOwnHostPort();
		$response = Ric_Rest_Client::get('http://'.$server.'/', ['info' => 1, 'token' => $this->configManager->getValue('adminToken')]);
		$info = json_decode($response, true);
		if( isset($info['config']['servers']) ){
			$serversToJoin = $info['config']['servers'];                    // get servers from cluster server
			$serversToJoin[] = $server;                                     // add cluster server
			$serversToJoin = array_diff($serversToJoin, [$ownServer]);      // remove me in case of an double joinCluster glitch
			$serversToJoin = array_unique($serversToJoin);                  // make it unique
			$joinedServers = [];
			foreach( $serversToJoin as $clusterServer ){
				$response = Ric_Rest_Client::post('http://'.$clusterServer.'/', ['action' => 'addServer', 'addServer' => $ownServer, 'token' => $this->configManager->getValue('adminToken')]);
				if( !$this->isResponseStatusOk($response) ){
					throw new RuntimeException('join cluster failed! addServer to '.$clusterServer.' failed! ['.$response.'] Inconsitent cluster state! I\'m added to this servers (please remove me): '.join('; ', $joinedServers), 400);
				}
				$joinedServers[] = $clusterServer;
			}
			$this->configManager->setRuntimeValue('servers', $serversToJoin);
		}else{
			throw new RuntimeException('cluster node is not responding properly', 400);
		}
	}

	/**
	 * remove selected or "all" servers
	 * @param $server
	 */
	public function removeServer($server){
		$this->removeServerFromConfig($server);
	}

	/**
	 * leaving a cluster
	 * send removeServer to all servers
	 * if it fails, the cluster is in inconsistent state, send leaveCluster command
	 * @throws RuntimeException
	 */
	public function leaveCluster(){
		$ownServer = $this->getOwnHostPort();
		list($leavedServers, $errorMsg) = $this->removeServerFromCluster($ownServer);
		$this->configManager->setRuntimeValue('servers', []);

		if( $errorMsg!='' ){
			throw new RuntimeException('leaveCluster failed! '.$errorMsg.' Inconsitent cluster state! (please remove me manually) succesfully removed from: '.join('; ', $leavedServers), 400);
		}
	}

	/**
	 * remove a server from the cluster
	 * send removeServer to all servers
	 * @param string $server
	 * @throws RuntimeException
	 */
	public function removeFromCluster($server){
		list($leavedServers, $errorMsg) = $this->removeServerFromCluster($server);
		if( $errorMsg!='' ){
			throw new RuntimeException('removeFromCluster failed! '.$errorMsg.' Inconsitent cluster state! (please remove me manually) succesfully removed from: '.join('; ', $leavedServers), 400);
		}
	}

	/**
	 * leaving a cluster
	 * send removeServer to all servers
	 * if it fails, the cluster is in inconsistent state, send leaveCluster command
	 * @param $server
	 * @return array
	 */
	protected function removeServerFromCluster($server){
		$leftServers = [];
		$errorMsg = '';
		foreach( $this->configManager->getValue('servers') as $clusterServer ){
			$response = Ric_Rest_Client::post('http://'.$clusterServer.'/', ['action' => 'removeServer', 'removeServer' => $server, 'token' => $this->configManager->getValue('adminToken')]);
			if( !$this->isResponseStatusOk($response) ){
				$errorMsg .= 'removeServer failed from '.$clusterServer.' failed! ['.$response.']';
			}else{
				$leftServers[] = $clusterServer;
			}
		}
		$this->removeServerFromConfig($server);
		return [$leftServers, $errorMsg];
	}

	/**
	 * todo check if parameter value all is wanted
	 * @param $server
	 */
	protected function removeServerFromConfig($server){
		if( $server=='all' ){
			$servers = [];
		}else{
			$servers = array_diff($this->configManager->getValue('servers'), [$server]);
		}
		$this->configManager->setRuntimeValue('servers', $servers);
	}

	/**
	 * @param string $fileName
	 * @param string $version
	 * @param string $sha1
	 * @return int
	 */
	public function getReplicaCount($fileName, $version, $sha1){
		$replicas = 0;
		foreach( $this->configManager->getValue('servers') as $server ){
			try{
				$serverUrl = 'http://'.$server.'/';
				// check file
				$url = $serverUrl.$fileName.'?check&version='.$version.'&sha1='.$sha1.'&minReplicas=0&token='.$this->configManager->getValue('readerToken'); // &minReplicas=0  otherwise loopOfDeath
				$response = Ric_Rest_Client::get($url);
				if( $this->isResponseStatusOk($response) ){
					$replicas++;
				}
			}catch(Exception $e){
				// unwichtig
			}
		}
		return $replicas;
	}

	/**
	 * sync file to other servers
	 * upload (put) if necessary
	 * returns empty string if all is fine
	 * @param $fileName
	 * @param string $filePath
	 * @param string $retention
	 * @return array|string
	 */
	public function syncFile($fileName, $filePath, $retention){
		$result = '';
		$sha1 = sha1_file($filePath);
		foreach( $this->configManager->getValue('servers') as $server ){
			try{
				$result .= $this->pushFileToServer($server, $fileName, $filePath, $retention, $sha1);
			}catch(Exception $e){
				$result .= 'failed to upload to '.$server.PHP_EOL;
			}
		}
		return trim($result);
	}

	/**
	 * returns empty string on success
	 * @param string $server
	 * @param $fileName
	 * @param $filePath
	 * @param $retention
	 * @param $sha1
	 * @return string
	 */
	public function pushFileToServer($server, $fileName, $filePath, $retention, $sha1 = ''){
		$result = '';
		if( $sha1=='' ){
			$sha1 = sha1_file($filePath);
		}
		$timestamp = filemtime($filePath);
		$serverUrl = 'http://'.$server.'/';
		// try to refresh file, no retention allowed
		$url = $serverUrl.$fileName.'?sha1='.$sha1.'&timestamp='.$timestamp.'&noSync=1&token='.$this->configManager->getValue('writerToken');
		$response = Ric_Rest_Client::post($url);
		if( !$this->isResponseStatusOk($response) ){
			// refresh failed, upload
			$url = $serverUrl.$fileName.'?sha1='.$sha1.'&timestamp='.$timestamp.'&retention='.$retention.'&noSync=1&token='.$this->configManager->getValue('writerToken');
			$response = Ric_Rest_Client::putFile($url, $filePath);
			if( !$this->isResponseStatusOk($response) ){
				$result = 'failed to upload '.basename($filePath).' to '.$server.' :'.$response.PHP_EOL;
			}
		}
		return $result;
	}

	/**
	 * delete file from other servers
	 * returns deleted files count
	 * @param $fileName
	 * @param $version
	 * @param string $error
	 * @return int
	 */
	public function deleteFile($fileName, $version, &$error = ''){
		$deletedCount = 0;
		foreach( $this->configManager->getValue('servers') as $server ){
			try{
				$serverUrl = 'http://'.$server.'/';
				$url = $serverUrl.$fileName.'?version='.$version.'&noSync=1&token='.$this->configManager->getValue('writerToken');
				$serverResponse = Ric_Rest_Client::delete($url);
				$serverResult = json_decode($serverResponse, true);
				if( isset($serverResult['filesDeleted']) ){
					$deletedCount += $serverResult['filesDeleted'];
				}else{
					$error = trim($error."\n".'failed at delete from '.$server.' :'.$serverResponse);
				}
			}catch(Exception $e){
				$error = trim($error."\n".'failed to delete from '.$server.'!');
			}
		}
		return $deletedCount;
	}

	/**
	 * get the own address
	 * @throws RuntimeException
	 */
	public function getOwnHostPort(){
		static $hostName = '';
		if( empty($hostName) ){
			$hostName = $this->configManager->getValue('hostPort');
			if( empty($hostName) AND strstr(gethostname(), '.')!==false ){
				$hostName = gethostname();
			}
			if( empty($hostName) ){
				// autodetect host port
				$hostName = $_SERVER['HTTP_HOST'];
				if( $_SERVER['SERVER_PORT']!=80 ){
					$hostName .= ':'.$_SERVER['SERVER_PORT'];
				}
				$this->configManager->setRuntimeValue('hostPort', $hostName);
			}
			if( empty($hostName) ){
				throw new RuntimeException('wrong hostname: ['.$hostName.'] - hostPort in config is missing and "hostname"-command returns not an host name with ".", and autodetection faileds $_SERVER[HTTP_HOST], so can not perform remote operation, please set "hostPort" in config or hostname on host to a reachable value (FQH: ric.example.com:3333)');
			}
		}
		return $hostName;
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

}