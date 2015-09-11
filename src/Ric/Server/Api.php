<?php

class Ric_Server_Api {
	/**
	 * @var Ric_Server_Server
	 */
	protected $server;

	/**
	 * @var Ric_Server_Auth_Manager
	 */
	protected $authService;

	/**
	 * Ric_Server_Api constructor.
	 * @param Ric_Server_Server $server
	 * @param Ric_Server_Auth_Manager $authService
	 */
	public function __construct(Ric_Server_Server $server, Ric_Server_Auth_Manager $authService){
		$this->server = $server;
		$this->authService = $authService;
	}

	/**
	 * handle GETs
	 */
	public function handleRequest(){
		try{
			$this->checkServerVersion(H::getRP('minServerVersion', ''));
			$this->auth(Ric_Server_Auth_Definition::ROLE__READER, true);
			if( $_SERVER['REQUEST_METHOD']=='PUT' ){
				$this->handlePutRequest();
			}elseif( $_SERVER['REQUEST_METHOD']=='POST' OR H::getRP('method')=='post' ){
				$this->handlePostRequest();
			}elseif( $_SERVER['REQUEST_METHOD']=='GET' ){
				$this->handleGetRequest();
			}elseif( $_SERVER['REQUEST_METHOD']=='DELETE' OR H::getRP('method')=='delete' ){
				if( $this->auth(Ric_Server_Auth_Definition::ROLE__WRITER) ){
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
			echo H::json(['error' => $e->getMessage()]);

		}
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
			if( $action=='list' AND $this->auth(Ric_Server_Auth_Definition::ROLE__ADMIN, true) ){
				$this->actionListFileNames();
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
		}elseif( $action=='check' ){
			$this->actionCheck();
		}elseif( $action=='list' ){
			$this->actionListVersions();
		}elseif( $action=='' ){
			$this->actionSendFile();
		}else{
			throw new RuntimeException('unknown [GET] action ', 400);
		}
	}

	/**
	 * handle POST, file refresh
	 */
	protected function handlePostRequest(){
		$this->auth(Ric_Server_Auth_Definition::ROLE__WRITER);
		$action = H::getRP('action');
		if( parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)=='/' ){ // homepage
			// post actions
			if( $action=='addServer' AND $this->auth(Ric_Server_Auth_Definition::ROLE__ADMIN) ){
				$this->actionAddServer();
			}elseif( $action=='removeServer' AND $this->auth(Ric_Server_Auth_Definition::ROLE__ADMIN) ){
				$this->actionRemoveServer();
			}elseif( $action=='joinCluster' AND $this->auth(Ric_Server_Auth_Definition::ROLE__ADMIN) ){
				$this->actionJoinCluster();
			}elseif( $action=='leaveCluster' AND $this->auth(Ric_Server_Auth_Definition::ROLE__ADMIN) ){
				$this->actionLeaveCluster();
			}elseif( $action=='removeFromCluster' AND $this->auth(Ric_Server_Auth_Definition::ROLE__ADMIN) ){
				$this->actionRemoveFromCluster();
			}else{
				throw new RuntimeException('unknown [POST] action or no file given', 400);
			}
		}else{
			if( $action=='push' AND $this->auth(Ric_Server_Auth_Definition::ROLE__ADMIN) ){
				$this->actionPushFileToServer();
			}else{
				// not "/" .. this is a file, refresh action
				$this->actionPostRefresh();
			}
		}
	}

	/**
	 * handle PUT
	 */
	protected function handlePutRequest(){
		$this->auth(Ric_Server_Auth_Definition::ROLE__WRITER);
		$retention = H::getRP('retention', Ric_Server_Definition::RETENTION__LAST3);
		$timestamp = (int) H::getRP('timestamp', time());
		$noSync = (bool) H::getRP('noSync');

		$tmpFilePath = $this->readInputStreamToTempFile();

		$fileName = $this->extractFileNameFromRequest();
		$response = $this->server->saveFileInCloud($tmpFilePath, $fileName, $retention, $timestamp, $noSync);
		$this->sendResponse($response);
	}

	/**
	 * @return string filepath
	 */
	protected function readInputStreamToTempFile(){
		$tmpFilePath = sys_get_temp_dir().'/_'.__CLASS__.'_'.uniqid('', true);
		$putData = fopen("php://input", "r");
		$fp = fopen($tmpFilePath, "w");
		stream_copy_to_stream($putData, $fp);
		fclose($fp);
		fclose($putData);
		return $tmpFilePath;
	}

	/**
	 * send an existing file
	 */
	public function actionSendFile(){
		$fileName = $this->extractFileNameFromRequest();
		$fileVersion = $this->extractVersionFromRequest();

		$response = $this->server->sendFile($fileName, $fileVersion);
		$this->sendResponse($response);
	}

	/**
	 * check and refresh a with a post request
	 * @throws RuntimeException
	 */
	protected function actionPostRefresh(){
		$version = H::getRP('sha1');
		$timestamp = H::getRP('timestamp', time());
		$noSync = (bool) H::getRP('noSync');

		if( H::getRP('retention')!==null ){
			throw new RuntimeException('parameter retention is not allowed on post action', 400);
		}
		if( $version=='' ){
			throw new RuntimeException('?sha1=1342.. is required', 400);
		}
		$fileName = $this->extractFileNameFromRequest();
		$response = $this->server->refreshFile($fileName, $version, $timestamp, $noSync);
		$this->sendResponse($response);
	}

	/**
	 * check and refresh a with a post request
	 * @throws RuntimeException
	 */
	protected function actionPushFileToServer(){
		$server = H::getRP('server');
		$version = H::getRP('version');
		if( $version=='' ){
			throw new RuntimeException('parameter version=1342.. is required', 400);
		}
		$fileName = $this->extractFileNameFromRequest();
		$response = $this->server->pushFileToServer($server, $fileName, $version);
		$this->sendResponse($response);
	}

	/**
	 * actionAddServer
	 */
	protected function actionAddServer(){
		$server = H::getRP('addServer');
		$response = $this->server->addServer($server);
		$this->sendResponse($response);
	}

	/**
	 * remove selected or "all" servers
	 */
	public function actionRemoveServer(){
		$server = H::getRP('removeServer');
		$response = $this->server->removeServer($server);
		$this->sendResponse($response);
	}

	/**
	 * join a existing cluster
	 * get all servers of the given clusterMember an send an addServer to all
	 * if it fails, the cluster is in inconsistent state, send leaveCluster command
	 */
	protected function actionJoinCluster(){
		$server = H::getRP('joinCluster');
		$response = $this->server->joinCluster($server);
		$this->sendResponse($response);
	}

	/**
	 * leaving a cluster
	 * send removeServer to all servers
	 * if it fails, the cluster is in inconsistent state, send leaveCluster command
	 */
	protected function actionLeaveCluster(){
		$response = $this->server->leaveCluster();
		$this->sendResponse($response);
	}

	/**
	 * remove a server from the cluster
	 * send removeServer to all servers
	 */
	protected function actionRemoveFromCluster(){
		$server = H::getRP('removeFromCluster');
		$response = $this->server->removeFromCluster($server);
		$this->sendResponse($response);
	}

	/**
	 * check for all servers
	 * quota <85%; every server knowns every server
	 *
	 * get server info
	 */
	protected function actionHealth(){
		$isAdmin = $this->auth(Ric_Server_Auth_Definition::ROLE__ADMIN, false);
		$response = $this->server->getHealthInfo($isAdmin);
		$this->sendResponse($response);
	}

	/**
	 * get server info
	 */
	public function actionInfo(){
		$isAdmin = $this->auth(Ric_Server_Auth_Definition::ROLE__ADMIN, false);
		$response = $this->server->showServerInfo($isAdmin);
		$this->sendResponse($response);
	}

	/**
	 * mark one or all versions of the File as deleted
	 */
	protected function actionDelete(){
		$fileName = $this->extractFileNameFromRequest();
		$version = $this->extractVersionFromRequest();
		$noSync = (bool) H::getRP('noSync');

		$response = $this->server->deleteFile($fileName, $version, $noSync);
		$this->sendResponse($response);
	}

	/**
	 * check file
	 */
	protected function actionCheck(){
		$fileName = $this->extractFileNameFromRequest();
		$version = $this->extractVersionFromRequest();

		$sha1 = H::getRP('sha1', '');
		$minSize = H::getRP('minSize', 1);
		$minTimestamp = H::getRP('minTimestamp', 0); // default no check
		$minReplicas = H::getRP('minReplicas', null); // if parameter omitted, don't check replicas!!!! or deadlock

		$response = $this->server->checkFile($fileName, $version, $sha1, $minSize, $minTimestamp, $minReplicas);
		$this->sendResponse($response);
	}

	/**
	 * list files
	 * @throws RuntimeException
	 */
	protected function actionListFileNames(){
		$pattern = H::getRP('pattern', null);
		if( $pattern!==null AND !Ric_Server_Helper_RegexValidator::isValid($pattern, $errorMessage) ){
			throw new RuntimeException('not a valid regex: '.$errorMessage, 400);
		}
		$start = H::getRP('start', 0);
		$limit = min(1000, H::getRP('limit', 100));

		$response = $this->server->listFileNames($pattern, $start, $limit);
		$this->sendResponse($response);
	}

	/**
	 * list versions of file
	 */
	protected function actionListVersions(){
		$limit = min(1000, H::getRP('limit', 100));
		$fileName = $this->extractFileNameFromRequest();

		$response = $this->server->listVersions($fileName, $limit);
		$this->sendResponse($response);
	}

	/**
	 * help
	 */
	protected function actionHelp(){
		$response = $this->server->getHelpInfo();
		$this->sendResponse($response);
	}

	protected function extractFileNameFromRequest(){
		$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
		$fileName = basename($path);
		if( ltrim($path, DIRECTORY_SEPARATOR)!=$fileName ){
			throw new RuntimeException('a path is not allowed! use server.com/file.name', 400);
		}
		$allowedChars = '1234567890abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
		$allowedChars .= '-_.';
		if( preg_match('~[^'.preg_quote($allowedChars, '~').']~', $fileName) ){
			throw new RuntimeException('filename must only use these chars: '.$allowedChars, 400);
		}
		return $fileName;
	}

	protected function extractVersionFromRequest(){
		$version = '';
		if( !empty($_REQUEST['version']) ){
			if( !ctype_alnum($_REQUEST['version']) ){
				throw new RuntimeException('invalid version', 400);
			}
			$version = $_REQUEST['version'];
		}
		return $version;
	}

	/**
	 * user admin>writer>reader
	 * @param string $user
	 * @param bool $isRequired
	 * @return bool
	 */
	protected function auth($user = Ric_Server_Auth_Definition::ROLE__READER, $isRequired = true){
		return $this->authService->auth($user, $isRequired);
	}

	/**
	 * check if server version >= minVersion and check if client major version==server major version
	 * @param $minServerVersion
	 */
	protected function checkServerVersion($minServerVersion){
		if( $minServerVersion!='' ){
			if( version_compare($minServerVersion, Ric_Server_Server::VERSION, '>') ){
				throw new RuntimeException('server to old ['.Ric_Server_Server::VERSION.']', 500);
			}
			if( preg_replace('~\..*~', '', $minServerVersion)!==preg_replace('~\..*~', '', Ric_Server_Server::VERSION) ){
				throw new RuntimeException('client ['.preg_replace('~[^\d\.]~', '', $minServerVersion).'] matches not the server major version ['.Ric_Server_Server::VERSION.']', 500);
			}
			if( version_compare('1.0.0', Ric_Server_Server::VERSION, '>') AND preg_replace('~^0.(\d+).*~', '\\1', $minServerVersion)!==preg_replace('~^0.(\d+).*~', '\\1', Ric_Server_Server::VERSION) ){
				throw new RuntimeException('client ['.$minServerVersion.'] matches not the server minor version (<1.0.0) version ['.Ric_Server_Server::VERSION.']', 500);
			}
		}
	}

	/**
	 * @param Ric_Server_Response $response
	 */
	protected function sendResponse(Ric_Server_Response $response){
		foreach( $response->getHeaders() as $header ){
			if( isset($header['code']) AND $header['code']>0 ){
				header($header['text'], $header['code']);
			}else{
				header($header['text']);
			}
		}
		$result = $response->getResult();
		$outputFilePath = $response->getOutputFilePath();
		if( $outputFilePath!='' ){
			readfile($outputFilePath);
		}elseif( $result!==null ){
			header('Content-Type: application/json');
			echo H::json($result);
		}else{
			foreach( $response->getOutput() as $output ){
				echo $output;
			}
		}
	}
}