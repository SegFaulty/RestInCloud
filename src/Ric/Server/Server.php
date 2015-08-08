<?php

/**
 * Class Ric_Server_Server
 */
class Ric_Server_Server {

    /**
     * @var Ric_Server_Config
     */
    protected $configService;

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
     * @param Ric_Server_Config $configService
     */
    public function __construct($configService){
        $this->configService = $configService;
        $config = $configService->getConfig();
        if(empty($config)){
            throw new RuntimeException('No config found');
        }
        $this->fileManager = new Ric_Server_File_Manager($this->configService->get('storeDir'));
        $this->clusterManager = new Ric_Server_Cluster_Manager($configService);
    }


//    /**
//     * execute cli command
//     * @param array $argv
//     * @throws RuntimeException
//     */
//    static public function cliPurge($argv){
//        $storeDir = $argv[2];
//        $maxTimestamp = $argv[3];
//        if( $maxTimestamp<=1 OR $maxTimestamp>time()+86400 ){
//            throw new RuntimeException('invalid timestamp (now:'.time().')');
//        }
//        $ricServer = new Ric_Server_Server($storeDir);
//        echo H::json($ricServer->purge($maxTimestamp));
//    }

    /**
     * todo move that thing
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
        $dirIterator = new RecursiveDirectoryIterator($this->configService->get('storeDir'));
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
        $result = 'OK';

        $version = $this->fileManager->storeFile($fileName, $tmpFilePath);

        // check quota
        if( $this->configService->get('quota')>0 ){
            if( $this->fileManager->getDirectorySize()>$this->configService->get('quota')*1024*1024 ){
                $filePath = $this->fileManager->getFilePath($fileName, $version);
                unlink($filePath);
                throw new RuntimeException('Quota exceeded!', 507);
            }
        }

        // replicate
        $filePath = $this->fileManager->getFilePath($fileName, $version);
        $this->fileManager->updateTimestamp($fileName, $version, $timestamp);
        $syncResult = $this->clusterManager->syncFile($fileName, $version, $filePath, $timestamp, $retention, $noSync);
        if( $syncResult!='' ){
            $result = 'WARNING'.' :'.$syncResult;
        }

        $this->executeRetention($fileName, $retention);

        // todo differentiate between error and stdout
        // TODO kein 201 liefern wenn $syncResult!='' , da dann mindestens ein server nicht erreicht wurde, muss das hier komplett failen
//        header('HTTP/1.1 201 Created', true, 201);
        $response = new Ric_Server_Response();
        $response->addHeader('HTTP/1.1 201 Created', 201);
        $response->setOutput($result.PHP_EOL);
        return $response;
    }

    /**
     * check and refresh a with a post request
     * @param string $fileName
     * @param string $version
     * @param string $retention
     * @param int $timestamp
     * @param boolean $noSync
     * @return Ric_Server_Response
     */
    public function refreshFile($fileName, $version, $retention, $timestamp, $noSync){
        $result = '0';

        $filePath = $this->fileManager->getFilePath($fileName, $version);
        if( file_exists($filePath) ){
            $this->fileManager->updateTimestamp($fileName, $version, $timestamp);
            $syncResult = $this->clusterManager->syncFile($fileName, $version, $filePath, $timestamp, $retention, $noSync);
            $this->executeRetention($fileName, $retention);
            if( $syncResult=='' ){
                $result = '1';
            }
        }else{
            // file not found
            $result = '0';
        }
        $response = new Ric_Server_Response();
        $response->setOutput($result.PHP_EOL);
        return $response;
    }

    /**
     * mark one or all versions of the File as deleted
     * @param string $fileName
     * @param string $version
     * @return Ric_Server_Response
     */
    public function deleteFile($fileName, $version){
        $filesDeleted = $this->fileManager->deleteFile($fileName, $version);
        $response = new Ric_Server_Response();
        $response->setResult(['filesDeleted' => $filesDeleted]);
        return $response;
    }

    /**
     * todo check if $fileInfo->getVersion()==$fileInfo->getSha1()
     * list files or version of file
     * @param string $fileName
     * @param string $fileVersion
     * @param string $sha1
     * @param int $minSize
     * @param int $minTimestamp
     * @param int $minReplicas
     * @return Ric_Server_Response
     */
    public function checkFile($fileName, $fileVersion, $sha1, $minSize, $minTimestamp, $minReplicas=null){
        $result = [];
        $result['status'] = 'OK';
        $result['msg'] = '';

        if($minReplicas==null){
            $minReplicas = max(1, count($this->configService->get('servers'))-1); // min 1, or 1 invalid of current servers
        }

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
            $infos['replicas'] = $this->clusterManager->getReplicaCount($fileInfo->getName(), $fileInfo->getVersion(), $fileInfo->getSha1());
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
        $response = new Ric_Server_Response();
        $response->setResult($result);
        return $response;
    }

    /**
     * list files
     * @param string $pattern
     * @param int $start
     * @param int $limit
     * @param boolean $showDeleted
     * @return Ric_Server_Response
     */
    public function listFileNames($pattern, $start, $limit, $showDeleted=false){
        $fileInfos = $this->fileManager->getFileInfosForPattern($pattern, $showDeleted, $start, $limit);
        $lines = [];
        foreach( $fileInfos as $fileInfo ){/** @var Ric_Server_File_FileInfo $fileInfo */
            $fileName = $fileInfo->getName();
            if( !in_array($fileName, $lines) ){
                $lines[] = $fileName;
            }
        }

        $response = new Ric_Server_Response();
        $response->setResult($lines);
        return $response;
    }

    /**
     * list files
     * @param string $pattern
     * @param int $start
     * @param int $limit
     * @param boolean $showDeleted
     * @return Ric_Server_Response
     */
    public function listFileInfos($pattern, $start, $limit, $showDeleted=false){
        $fileInfos = $this->fileManager->getFileInfosForPattern($pattern, $showDeleted, $start, $limit);
        $lines = [];
        $index = $start;
        foreach( $fileInfos as $fileInfo ){/** @var Ric_Server_File_FileInfo $fileInfo */
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
        }

        $response = new Ric_Server_Response();
        $response->setResult($lines);
        return $response;
    }

    /**
     * list versions of file
     * @param string $fileName
     * @param int $limit
     * @param bool $showDeleted
     * @return Ric_Server_Response
     */
    public function listVersions($fileName, $limit, $showDeleted=false){
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
        $response = new Ric_Server_Response();
        $response->setResult($lines);
        return $response;
    }

    /**
     * todo necessary?
     * todo merge with grep
     * @param string $fileName
     * @param string $fileVersion
     * @param int $lines
     * @return Ric_Server_Response
     * @throws RuntimeException
     */
    public function getLinesFromFile($fileName, $fileVersion, $lines){
        $response = new Ric_Server_Response();
        $dataLines = $this->fileManager->getLinesFromFile($fileName, $fileVersion, $lines);
        foreach($dataLines as $line) {
            $response->addOutput($line);
        }
        return $response;
    }

    /**
     * todo necessary?
     * @param string $fileName
     * @param string $fileVersion
     * @param string $regex
     * @return Ric_Server_Response
     * @throws RuntimeException
     */
    public function grepFromFile($fileName, $fileVersion, $regex){
        $response = new Ric_Server_Response();
        $filePath = $this->fileManager->getFileInfosForPattern($fileName, $fileVersion);
        $fp = gzopen($filePath, 'r');
        if( !$fp ){
            throw new RuntimeException('open file failed');
        }
        while(($line = gzgets($fp,100000))){
            if( preg_match($regex, $line) ){
                $response->addOutput($line);
            }
        }
        gzclose($fp);
        return $response;
    }

    /**
     * get server info
     * @param bool $isAdmin
     * @return Ric_Server_Response
     */
    public function showServerInfo($isAdmin=false){
        $response = new Ric_Server_Response();
        $response->setResult($this->buildInfo($isAdmin));
        return $response;
    }

    /**
     * get server info
     * @param bool $isAdmin
     * @return array
     */
    protected function buildInfo($isAdmin=false){
        $info['serverTimestamp'] = time();
        $directorySize = $this->fileManager->getDirectorySize();
        $directorySizeMb = ceil($directorySize /1024/1024); // IN MB
        $info['usageByte'] = $directorySize;
        $info['usage'] = $directorySizeMb;
        $info['quota'] = $this->configService->get('quota');
        if( $this->configService->get('quota')>0 ){
            $info['quotaLevel'] = ceil($directorySizeMb/$this->configService->get('quota')*100);
            $info['quotaFreeLevel'] = max(0,min(100,100-ceil($directorySizeMb/$this->configService->get('quota')*100)));
            $info['quotaFree'] = max(0, intval($this->configService->get('quota')-$directorySizeMb));
        }
        if( $isAdmin ){ // only for admins
            $info['config'] = $this->configService->getConfig();
            $info['runtimeConfig'] = false;
            if( file_exists($this->configService->get('storeDir').'intern/config.json') ){
                $info['runtimeConfig'] = json_decode(file_get_contents($this->configService->get('storeDir').'intern/config.json'), true);
            }
            $info['defaultConfig'] = $this->configService->getDefaultConfig();
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
    public function getHealthInfo($isAdmin=false){
        $criticalQuotaFreeLevel = 15;
        $status = 'OK';
        $msg = '';

        $serversFailures = [];
        $ownHostPort = $this->getOwnHostPort();
        $clusterServers = array_merge([$ownHostPort], $this->configService->get('servers'));
        sort($clusterServers);
        // get serverInfos
        $serverInfos = [];
        $serverInfos[$ownHostPort] = $this->buildInfo(true);
        foreach( $this->configService->get('servers') as $server ){
            try{
                $url = 'http://'.$server.'/?info&token='.$this->configService->get('adminToken');
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
            $msg.= 'replication critical! servers running: '.count($serverInfos).' remoteServer configured: '.count($this->configService->get('servers')).PHP_EOL;
        }
        // ok servers:
        $msg.= 'servers ok: '.(count($this->configService->get('servers'))+1-count($serversFailures)).PHP_EOL;

        $response = new Ric_Server_Response();
        $response->addOutput($status.PHP_EOL);
        if( $isAdmin ){
            $response->addOutput($msg.PHP_EOL);
        }
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
        $retentions.= '     '.Ric_Server_Definition::RETENTION__OFF.' : keep only the last version'.PHP_EOL;
        $retentions.= '     '.Ric_Server_Definition::RETENTION__LAST3.' : keep last 3 versions'.PHP_EOL;
        $retentions.= '     '.Ric_Server_Definition::RETENTION__LAST7.' : keep last 7 versions'.PHP_EOL;
        $retentions.= '     '.Ric_Server_Definition::RETENTION__3L7D4W12M.' : keep last 3 versions then last of 7 days, 4 weeks, 12 month (max 23 Versions)'.PHP_EOL;
        $helpString = str_replace('{retentionList}', $retentions, $helpString);

        $response = new Ric_Server_Response();
        $response->addOutput('<pre>'.htmlentities($helpString).'</pre>');
        return $response;
    }

    /**
     * outputs the file size
     * @param string $fileName
     * @param string $version
     * @return Ric_Server_Response
     */
    public function showFileSize($fileName, $version){
        $response = new Ric_Server_Response();
        $filePath = $this->fileManager->getFilePath($fileName, $version);
        $response->addOutput(filesize($filePath));
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
        $response = new Ric_Server_Response();
        $fileInfo = $this->fileManager->getFileInfo($fileName, $fileVersion);

        $lastModified = gmdate('D, d M Y H:i:s \G\M\T', $fileInfo->getTimestamp());
        $eTag = $fileInfo->getSha1();

        // 304er support
        $ifMod = isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) ? $_SERVER['HTTP_IF_MODIFIED_SINCE'] == $lastModified : null;
        $ifTag = isset($_SERVER['HTTP_IF_NONE_MATCH']) ? $_SERVER['HTTP_IF_NONE_MATCH'] == $eTag : null;
        if (($ifMod || $ifTag) && ($ifMod !== false && $ifTag !== false)) {
//            header('HTTP/1.0 304 Not Modified');
            $response->addHeader('HTTP/1.0 304 Not Modified');
        } else {
            $filePath = $this->fileManager->getFilePath($fileName, $fileVersion);
            if(!file_exists($filePath)){
                throw new RuntimeException('File not found! '.$filePath, 404);
            }
            $response->addHeader('Content-Type: application/octet-stream');
            $response->addHeader('Content-Disposition: attachment; filename="'.$fileName.'"');
            $response->addHeader('Content-Length: '.$fileInfo->getSize());
            $response->addHeader('Last-Modified:'.$lastModified);
            $response->addHeader('ETag: '.$eTag);
//            readfile($filePath);
            $response->setOutput(file_get_contents($filePath));
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
        $response->setResult(['Status' => 'OK']);
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
        $response->setResult(['Status' => 'OK']);
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
        $response->setResult(['Status' => 'OK']);
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
        $response->setResult(['Status' => 'OK']);
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
        $response->setResult(['Status' => 'OK']);
        return $response;
    }

    /*** CLUSTER ***/
    /***************/

    /**
     * @param string $fileName
     * @param string $retention
     * @return Ric_Server_Response
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
            $this->fileManager->markFileAsDeleted($fileName, $version);
        }

        $response = new Ric_Server_Response();
        $response->setResult(['Status' => 'OK']);
        return $response;
    }

    /**
     * get the own address
     * @throws RuntimeException
     */
    public function getOwnHostPort(){
        static $hostName = '';
        if( empty($hostName) ){
            $hostAndPort = $this->configService->get('hostPort');
            if( !empty($hostAndPort) ){
                $hostName = $this->configService->get('hostPort');
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