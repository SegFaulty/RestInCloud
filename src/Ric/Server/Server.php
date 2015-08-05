<?php

/**
 * Class Ric_Server_Server
 */
class Ric_Server_Server {

    /**
     * @var Ric_Server_Auth_Service
     */
    protected $authService;

    protected $defaultConfig = [
        'hostPort' => '', // if empty use autoDetectSource host with default port
        'storeDir' => '/tmp/ric/',
        'quota' => 0,
        'servers' => [],
        'adminToken' => 'admin',
        'writerToken' => 'writer',
        'readerToken' => '',
        'defaultRetention' => Ric_Server_Definition::RETENTION__LAST3,
    ];

    protected $configLoader;
    protected $config = [];

    protected $fileManager;

    /**
     * construct
     */
    public function __construct($configFilePath=''){
        $this->configLoader = new Ric_Server_Config($this->defaultConfig);
        $this->config = $this->configLoader->loadConfig($configFilePath);
        if( !is_dir($this->config['storeDir']) OR !is_writable($this->config['storeDir']) ){
            throw new RuntimeException('document root ['.$this->config['storeDir'].'] is not a writable dir!');
        }
        $this->authService = new Ric_Server_Auth_Service($this->config);
        $this->fileManager = new Ric_Server_File_Manager($this->config['storeDir']);
    }

    /**
     * handle GETs
     */
    public function handleRequest(){
        try{
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
                if( $fileTimestamp<=$maxTimestamp AND $fileTimestamp==Ric_Server_Definition::MAGIC_DELETION_TIMESTAMP ){
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
     * set, update, remove (null) a value in runtimeConfig (and config)
     * @param string $key
     * @param string $value
     */
    protected function setRuntimeConfig($key, $value){
        $this->configLoader->setRuntimeConfig($key, $value);
        if($value!==null){
            $this->config[$key] = $value;
        }
    }

    /**
     * handle PUT
     */
    protected function handlePutRequest(){
        $this->auth(Ric_Server_Auth_Definition::ROLE__WRITER);
        $result = 'OK';
        $retention = H::getRP('retention', Ric_Server_Definition::RETENTION__LAST3);
        $timestamp = H::getRP('timestamp', time());
        $noSync = (bool) H::getRP('noSync');

        // read stream to tmpFile
        $tmpFilePath = sys_get_temp_dir().'/_'.__CLASS__.'_'.uniqid('', true);
        $putData = fopen("php://input", "r");
        $fp = fopen($tmpFilePath, "w");
        stream_copy_to_stream($putData, $fp);
#$bytesCopied = stream_copy_to_stream($putData, $fp);
#echo $bytesCopied.' bytes'.PHP_EOL;
#echo filesize($tmpFilePath).' tmp bytes'.PHP_EOL;
        fclose($fp);
        fclose($putData);


        $fileName = $this->extractFileNameFromRequest();
        $version = $this->fileManager->storeFile($fileName, $tmpFilePath);

        // check quota
        if( $this->config['quota']>0 ){
            if( $this->fileManager->getDirectorySize()>$this->config['quota']*1024*1024 ){
                $filePath = $this->fileManager->getFilePath($fileName, $version);
                unlink($filePath);
                throw new RuntimeException('Quota exceeded!', 507);
            }
        }

        // replicate
        $filePath = $this->fileManager->getFilePath($fileName, $version);
        $syncResult = $this->syncFile($filePath, $timestamp, $retention, $noSync);
        if( $syncResult!='' ){
            $result = 'WARNING'.' :'.$syncResult;
        }

        $this->executeRetention($fileName, $retention);

        // TODO kein 201 liefern wenn $syncResult!='' , da dann mindestens ein server nicht erreicht wurde, muss das hier komplett failen
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
            if( $action=='list' AND $this->auth(Ric_Server_Auth_Definition::ROLE__ADMIN) ){
                $this->actionList();
            }elseif( $action=='listDetails' AND $this->auth(Ric_Server_Auth_Definition::ROLE__ADMIN) ){
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
        $version = H::getRP('sha1');
        $retention = H::getRP('retention', '');
        $timestamp = H::getRP('timestamp', time());
        $noSync = (bool) H::getRP('noSync');

        if( $version=='' ){
            throw new RuntimeException('?sha1=1342.. is required', 400);
        }

        $fileName = $this->extractFileNameFromRequest();
        $filePath = $this->fileManager->getFilePath($fileName, $version);
        if( file_exists($filePath) ){
            $syncResult = $this->syncFile($filePath, $timestamp, $retention, $noSync);
            $this->executeRetention($fileName, $retention);
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
    protected function auth($user=Ric_Server_Auth_Definition::ROLE__READER, $isRequired=true){
        return $this->authService->auth($user, $isRequired);
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
        $this->removeServer($server);

        header('Content-Type: application/json');
        echo H::json(['Status' => 'OK']);
    }

    /**
     * @param $server
     */
    protected function removeServer($server){
        if( $server=='all' ){
            $servers = [];
        } else {
            $servers = array_diff($this->config['servers'], [$server]);
        }
        $this->setRuntimeConfig('servers', $servers);
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
            $this->setRuntimeConfig('servers', $servers);

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
        list($leavedServers, $errorMsg) = $this->removeServerFromCluster($ownServer);
        $this->setRuntimeConfig('servers', []);

        if( $errorMsg!='' ){
            throw new RuntimeException('leaveCluster failed! '.$errorMsg.' Inconsitent cluster state! (please remove me manually) succesfully removed from: '.join('; ', $leavedServers), 400);
        }
        header('Content-Type: application/json');
        echo H::json(['Status' => 'OK']);
    }

    /**
     * remove a server from the cluster
     * send removeServer to all servers
     * @throws RuntimeException
     */
    protected function actionRemoveFromCluster(){
        $server = H::getRP('removeFromCluster');
        list($leavedServers, $errorMsg) = $this->removeServerFromCluster($server);
        if( $errorMsg!='' ){
            throw new RuntimeException('removeFromCluster failed! '.$errorMsg.' Inconsitent cluster state! (please remove me manually) succesfully removed from: '.join('; ', $leavedServers), 400);
        }
        header('Content-Type: application/json');
        echo H::json(['Status' => 'OK']);
    }

    /**
     * leaving a cluster
     * send removeServer to all servers
     * if it fails, the cluster is in inconsistent state, send leaveCluster command
     * @param $server
     * @return array
     */
    protected function removeServerFromCluster($server){
        $leavedServers = [];
        $errorMsg = '';
        foreach( $this->config['servers'] as $clusterServer ){
            $response = Ric_Rest_Client::post('http://' . $clusterServer . '/', ['action' => 'removeServer', 'removeServer' => $server, 'token' => $this->config['adminToken']]);
            $result = json_decode($response, true);
            if( H::getIKS($result, 'Status')!='OK' ){
                $errorMsg.= 'removeServer failed from '.$clusterServer.' failed! ['.$response.']';
            }else{
                $leavedServers[] = $clusterServer;
            }
        }
        $this->removeServer($server);
        return [$leavedServers, $errorMsg];
    }


    /**
     * mark one or all versions of the File as deleted
     */
    protected function actionDelete(){
        $fileName = $this->extractFileNameFromRequest();
        $version = $this->extractVersionFromRequest();
        $filesDeleted = 0;
        if( $version ){
            $filePath = $this->fileManager->getFilePath($fileName, $version);
            $filesDeleted+= $this->markFileDeleted($filePath);
        }else{
            foreach( $this->fileManager->getAllVersions($fileName) as $version=>$timestamp){
                $filePath = $this->fileManager->getFilePath($fileName, $version);
                $filesDeleted+= $this->markFileDeleted($filePath);
            }
        }
        header('Content-Type: application/json');
        echo H::json(['filesDeleted' => $filesDeleted]);
    }

    /**
     * todo check if $fileInfo->getVersion()==$fileInfo->getSha1()
     * list files or version of file
     * @throws RuntimeException
     */
    protected function actionVerify(){
        $result = [];
        $result['status'] = 'OK';
        $result['msg'] = '';
        $fileName = $this->extractFileNameFromRequest();
        $fileVersion = $this->extractVersionFromRequest();

        $sha1 = H::getRP('sha1', '');
        $minSize = H::getRP('minSize', 1);
        $minTimestamp = H::getRP('minTimestamp', 0); // default no check
        $minReplicasDefault = max(1, count($this->config['servers'])-1); // min 1, or 1 invalid of current servers
        $minReplicas = H::getRP('minReplicas', $minReplicasDefault); // if parameter omitted, don't check replicas!!!! or deadlock

        $fileInfo = $this->fileManager->getFileInfo($fileName, $fileVersion);
        $infos = [
            'name' => $fileInfo->getName(),
            'version' => $fileInfo->getVersion(),
            'sha1' => $fileInfo->getSha1(),
            'dateTime' => $fileInfo->getDateTime(),
            'timestamp' => $fileInfo->getTimestamp(),
            'size' => $fileInfo->getSize(),
        ];
        $infos['replicas'] = false;
        if( $minReplicas>0 ){
            $infos['replicas'] = $this->getReplicaCount($fileInfo->getName(), $fileInfo->getVersion(), $fileInfo->getSha1());
        }
        if( $sha1!='' AND $infos['sha1']!=$sha1 ){
            $result['status'] = 'CRITICAL';
            $result['msg'] = trim($result['msg'].PHP_EOL.'unmatched sha1');
        }
        if( $infos['size']<$minSize ){
            $result['status'] = 'CRITICAL';
            $result['msg'] = trim($result['msg'].PHP_EOL.'size less then expected ('.$infos['size'].'/'.$minSize.')');
        }
        if( $minTimestamp>0 AND $infos['timestamp']<$minTimestamp ){
            $result['status'] = 'CRITICAL';
            $result['msg'] = trim($result['msg'].PHP_EOL.'file is outdated ('.$infos['timestamp'].'/'.$minTimestamp.')');
        }
        if( $minReplicas>0 AND $infos['replicas']<$minReplicas ){
            $result['status'] = 'CRITICAL';
            // wenn wir mindestens replikat haben und nur eins fehlt, dann warning (das wird mutmasslich gerade gelöst)
            if( $infos['replicas']>0 AND $infos['replicas']>=$minReplicas-1 ){
                $result['status'] = 'WARNING';
            }
            $result['msg'] = trim($result['msg'].PHP_EOL.'not enough replicas ('.$infos['replicas'].'/'.$minReplicas.')');
        }
        $result['fileInfo'] = $infos;
        header('Content-Type: application/json');
        echo H::json($result);
    }

    /**
     * @param string $fileName
     * @param string $version
     * @param string $sha1
     * @return int
     */
    protected function getReplicaCount($fileName, $version, $sha1){
        $replicas = 0;
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
        $pattern = H::getRP('pattern', null);
        if($pattern!==null AND !Ric_Server_Helper_RegexValidator::isValid($pattern, $errorMessage)){
            throw new RuntimeException('not a valid regex: '.$errorMessage, 400);
        }
        $showDeleted = H::getRP('showDeleted', false);
        if($showDeleted==='true' OR $showDeleted==='1'){
            $showDeleted = true;
        }else{
            $showDeleted = false;
        }
        $start = H::getRP('start', 0);
        $limit = min(1000, H::getRP('limit', 100));

        $fileInfos = $this->fileManager->getFileInfosForPattern($pattern, $showDeleted, $start, $limit);
        $lines = [];
        $index = $start;
        foreach( $fileInfos as $fileInfo ){/** @var Ric_Server_File_FileInfo $fileInfo */
            if( $details ){
                $lines[] = [
                    'index' => $index,
                    'name' => $fileInfo->getName(),
                    'version' => $fileInfo->getVersion(),
                    'sha1' => $fileInfo->getSha1(),
                    'dateTime' => $fileInfo->getDateTime(),
                    'timestamp' => $fileInfo->getTimestamp(),
                    'size' => $fileInfo->getSize(),
                ];
                $index++;
            }else{
                $fileName = $fileInfo['name'];
                if( !in_array($fileName, $lines) ){
                    $lines[] = $fileName;
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

        $fileName = $this->extractFileNameFromRequest();

        $lines = [];
        $index = -1;
        foreach( $this->fileManager->getAllVersions($fileName, $showDeleted) as $version=>$timeStamp ){
            $index++;
            if( $limit<=$index ){
                break;
            }
            $fileInfo = $this->fileManager->getFileInfo($fileName, $version);
            $lines[] = [
                'index' => $index,
                'name' => $fileInfo->getName(),
                'version' => $fileInfo->getVersion(),
                'sha1' => $fileInfo->getSha1(),
                'dateTime' => $fileInfo->getDateTime(),
                'timestamp' => $fileInfo->getTimestamp(),
                'size' => $fileInfo->getSize(),
            ];

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
        $regex = H::getRP('grep');
        if(!Ric_Server_Helper_RegexValidator::isValid($regex, $errorMessage)){
            throw new RuntimeException('not a valid regex: '.$errorMessage, 400);
        }
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
        echo H::json($this->buildInfo($this->auth(Ric_Server_Auth_Definition::ROLE__ADMIN, false)));
    }

    /**
     * get server info
     */
    protected function buildInfo($isAdmin=false){
        $info['serverTimestamp'] = time();
        $directorySize = $this->fileManager->getDirectorySize();
        $directorySizeMb = ceil($directorySize /1024/1024); // IN MB
        $info['usageByte'] = $directorySize;
        $info['usage'] = $directorySizeMb;
        $info['quota'] = $this->config['quota'];
        if( $this->config['quota']>0 ){
            $info['quotaLevel'] = ceil($directorySizeMb/$this->config['quota']*100);
            $info['quotaFreeLevel'] = max(0,min(100,100-ceil($directorySizeMb/$this->config['quota']*100)));
            $info['quotaFree'] = max(0, intval($this->config['quota']-$directorySizeMb));
        }
        if( $isAdmin ){ // only for admins
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
     * check for all servers
     * quota <85%; every server knowns every server
     *
     * get server info
     */
    protected function actionHealth(){
        $criticalQuotaFreeLevel = 15;
        $status = 'OK';
        $msg = '';

        $serversFailures = [];
        $clusterServers = array_merge([$this->getOwnHostPort()], $this->config['servers']);
        sort($clusterServers);
        // get serverInfos
        $serverInfos = [];
        $serverInfos[$this->getOwnHostPort()] = $this->buildInfo(true);
        foreach( $this->config['servers'] as $server ){
            try{
                $url = 'http://'.$server.'/?info&token='.$this->config['adminToken'];
                $result = json_decode(Ric_Rest_Client::get($url), true);
                if( !array_key_exists('usageByte', $result) ){
                    throw new RuntimeException('info failed');
                }
                $serverInfos[$server] = $result;
            }catch(Exception $e){
                $serversFailures[$server] = $e->getMessage();
            }
        }
        // check serverInfos
        foreach( $serverInfos as $server=>$serverInfo ){
            // check quota
            if( array_key_exists('quotaFreeLevel', $serverInfo) AND $serverInfo['quotaFreeLevel']<$criticalQuotaFreeLevel ){
                $serversFailures[$server][] = 'quotaFreeLevel:'. $serverInfo['quotaFreeLevel'].'%';
            }
            // check servers
            $expectedClusterServers = array_diff($clusterServers, [$server]);
            rsort($expectedClusterServers);
            if( array_values($expectedClusterServers)!=array_values($serverInfo['config']['servers']) ){
                $serversFailures[$server][] = 'unxpected clusterServer: '.join(',', $serverInfo['config']['servers']). ' (expected: '.join(',', $expectedClusterServers).')';
            }
        }
        // build quota info
        foreach( $serverInfos as $server=>$serverInfo ){
            $msg.= $server.' '.($serverInfo['quota']-$serverInfo['usage']).' MB free of '.$serverInfo['quota'].' MB '.' used '.$serverInfo['usage'].' MB ('.$serverInfo['quotaLevel'].'%)'.PHP_EOL;
        }
        // check failedServers
        if( !empty($serversFailures) ){
            $status = 'WARNING';
            $msg.= 'servers with failure: '.count($serversFailures).PHP_EOL;
            foreach( $serversFailures as $server=>$serverError ){
                $msg.= $server.' '.implode('; ', $serverError).PHP_EOL;
            }
        }
        // check if cluster in critical state
        if( count($serverInfos)<2 OR count($serversFailures)>1 ){
            $status = 'CRITICAL';
            $msg.= 'replication critical! servers running: '.count($serverInfos).' remoteServer configured: '.count($this->config['servers']).PHP_EOL;
        }
        // ok servers:
        $msg.= 'servers ok: '.(count($this->config['servers'])+1-count($serversFailures)).PHP_EOL;

        echo $status.PHP_EOL;
        if( $this->auth(Ric_Server_Auth_Definition::ROLE__ADMIN, false) ){
            echo $msg.PHP_EOL;
        }
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
        $helpString = str_replace('ric1.server', $_SERVER['HTTP_HOST'], $helpString);
        $retentions = '';
        $retentions.= '     '.Ric_Server_Definition::RETENTION__OFF.' : keep only the last version'.PHP_EOL;
        $retentions.= '     '.Ric_Server_Definition::RETENTION__LAST3.' : keep last 3 versions'.PHP_EOL;
        $retentions.= '     '.Ric_Server_Definition::RETENTION__LAST7.' : keep last 7 versions'.PHP_EOL;
        $retentions.= '     '.Ric_Server_Definition::RETENTION__3L7D4W12M.' : keep last 3 versions then last of 7 days, 4 weeks, 12 month (max 23 Versions)'.PHP_EOL;
        $helpString = str_replace('{retentionList}', $retentions, $helpString);
        echo '<pre>'.htmlentities($helpString).'</pre>';
    }

    /**
     * send an existing file
     */
    protected function actionSendFile(){
        $fileName = $this->extractFileNameFromRequest();
        $fileVersion = $this->extractVersionFromRequest();
        $fileInfo = $this->fileManager->getFileInfo($fileName, $fileVersion);

        $lastModified = gmdate('D, d M Y H:i:s \G\M\T', $fileInfo->getTimestamp());
        $eTag = $fileInfo->getSha1();

        // 304er support
        $ifMod = isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) ? $_SERVER['HTTP_IF_MODIFIED_SINCE'] == $lastModified : null;
        $ifTag = isset($_SERVER['HTTP_IF_NONE_MATCH']) ? $_SERVER['HTTP_IF_NONE_MATCH'] == $eTag : null;
        if (($ifMod || $ifTag) && ($ifMod !== false && $ifTag !== false)) {
            header('HTTP/1.0 304 Not Modified');
        } else {
            $filePath = $this->fileManager->getFilePath($fileName, $fileVersion);
            if(!file_exists($filePath)){
                throw new RuntimeException('File not found! '.$filePath, 404);
            }
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="'.$fileName.'"');
            header('Content-Length: '.$fileInfo->getSize());
            header('Last-Modified:'.$lastModified);
            header('ETag: '.$eTag);
            readfile($filePath);
        }
    }

    /**
     * @param string $fileName
     * @param string $retention
     * @throws RuntimeException
     */
    protected function executeRetention($fileName, $retention){
        $allVersions = $this->fileManager->getAllVersions($fileName);
        $deleteFilePaths = [];
        switch( $retention ){
            case '':
                // do nothing
                break;
            case Ric_Server_Definition::RETENTION__OFF:
                $deleteFilePaths = array_slice(array_keys($allVersions),1); // remove from 3
                break;
            case Ric_Server_Definition::RETENTION__LAST3:
                $deleteFilePaths = array_slice(array_keys($allVersions),3); // remove from 3
                break;
            case Ric_Server_Definition::RETENTION__LAST7:
                $deleteFilePaths = array_slice(array_keys($allVersions),7);
                break;
            default:
                throw new RuntimeException('unknown retention strategy', 400);
        }
        foreach( $deleteFilePaths as $version ){
            $filePath = $this->fileManager->getFilePath($fileName, $version);
            $this->markFileDeleted($filePath);
        }
    }

    /**
     * @param string $filePath
     * @return bool
     */
    protected function markFileDeleted($filePath){
        $result = false;
        if( file_exists($filePath) AND filemtime($filePath)!=Ric_Server_Definition::MAGIC_DELETION_TIMESTAMP ){
            $result = touch($filePath, Ric_Server_Definition::MAGIC_DELETION_TIMESTAMP) ;
        }
        return $result;
    }

    protected function extractFileNameFromRequest(){
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
     * @param string $version
     * @throws RuntimeException
     * @return string
     */
    protected function getFilePath($version=''){
        $filePath = '';
        $fileName = $this->extractFileNameFromRequest();
        if( $fileName!='' ){
            if( $version ){
                $fileVersion = $version;
            }else{
                $fileVersion = $this->extractVersionFromRequest();
            }
            $filePath = $this->fileManager->getFilePath($fileName, $fileVersion);
        }
        // if we not create a new file, it must exists
        if( $version=='' AND $filePath AND !file_exists($filePath) ){
            throw new RuntimeException('File not found! '.$filePath, 404);
        }
        return $filePath;
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
        static $hostName = '';
        if( empty($hostName) ){
            if( !empty($this->config['hostPort']) ){
                $hostName = $this->config['hostPort'];
            }else{
                $hostName = gethostname(); // servers host name
            }
        }
        if( empty($hostName) OR strstr($hostName, '.')===false ){
            throw new RuntimeException('wrong hostname: ['.$hostName.'] - hostPort in config is missing and "hostname"-command returns not an host name with ".",  can not perform remote operation, please set "hostPort" in config or hostname on host to a reachable value (FQH: ric.example.com:3333)');
        }
        return $hostName;
    }


}