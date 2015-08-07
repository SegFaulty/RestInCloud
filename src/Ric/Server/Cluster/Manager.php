<?php
/**
 * @todo
 * syncfile
 * verify
 *
 * (dump, restore)
 */

class Ric_Server_Cluster_Manager{

    /**
     * @var Ric_Server_Config
     */
    protected $configService;

    /**
     * Ric_Server_Cluster_Manager constructor.
     * @param Ric_Server_Config $configService
     */
    public function __construct(Ric_Server_Config $configService) {
        $this->configService = $configService;
    }

    /**
     * @param string $server
     * @throws RuntimeException
     */
    public function addServer($server){
        $response = Ric_Rest_Client::get('http://' . $server . '/', ['info' => 1, 'token' => $this->configService->get('readerToken')]);
        $info = json_decode($response, true);
        if( $info AND H::getIKS($info, 'serverTimestamp') ){
            $servers = $this->configService->get('servers');
            $servers[] = $server;
            $this->configService->setRuntimeConfig('servers', $this->configService->get('servers'));
        }else{
            throw new RuntimeException('server is not responding properly', 400);
        }
        header('Content-Type: application/json');
        echo H::json(['Status' => 'OK']);
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
        $response = Ric_Rest_Client::get('http://' . $server . '/', ['info' => 1, 'token' => $this->configService->get('adminToken')]);
        $info = json_decode($response, true);
        if( isset($info['config']['servers']) ){
            $servers = $info['config']['servers'];
            $joinedServers = [];
            $servers[] = $server;
            foreach( $servers as $clusterServer ){
                $response = Ric_Rest_Client::post('http://' . $clusterServer . '/', ['action' => 'addServer', 'addServer' => $ownServer, 'token' => $this->configService->get('adminToken')]);
                $result = json_decode($response, true);
                if( H::getIKS($result, 'Status')!='OK' ){
                    throw new RuntimeException('join cluster failed! addServer to '.$clusterServer.' failed! ['.$response.'] Inconsitent cluster state! I\'m added to this servers (please remove me): '.join('; ', $joinedServers), 400);
                }
                $joinedServers[] = $clusterServer;
            }
            $this->configService->setRuntimeConfig('servers', $servers);

            // todo  pull a dump and restore

        }else{
            throw new RuntimeException('cluster node is not responding properly', 400);
        }
        header('Content-Type: application/json');
        echo H::json(['Status' => 'OK']);
    }

    /**
     * remove selected or "all" servers
     * @param $server
     */
    public function removeServer($server){
        $this->removeServerFromConfig($server);

        header('Content-Type: application/json');
        echo H::json(['Status' => 'OK']);
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
        $this->configService->setRuntimeConfig('servers', []);

        if( $errorMsg!='' ){
            throw new RuntimeException('leaveCluster failed! '.$errorMsg.' Inconsitent cluster state! (please remove me manually) succesfully removed from: '.join('; ', $leavedServers), 400);
        }
        header('Content-Type: application/json');
        echo H::json(['Status' => 'OK']);
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
        $leftServers = [];
        $errorMsg = '';
        foreach( $this->configService->get('servers') as $clusterServer ){
            $response = Ric_Rest_Client::post('http://' . $clusterServer . '/', ['action' => 'removeServer', 'removeServer' => $server, 'token' => $this->configService->get('adminToken')]);
            $result = json_decode($response, true);
            if( H::getIKS($result, 'Status')!='OK' ){
                $errorMsg.= 'removeServer failed from '.$clusterServer.' failed! ['.$response.']';
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
        } else {
            $servers = array_diff($this->configService->get('servers'), [$server]);
        }
        $this->configService->setRuntimeConfig('servers', $servers);
    }

    /**
     * @param string $fileName
     * @param string $version
     * @param string $sha1
     * @return int
     */
    public function getReplicaCount($fileName, $version, $sha1){
        $replicas = 0;
        foreach( $this->configService->get('servers') as $server ){
            try{
                $serverUrl = 'http://'.$server.'/';
                // check file
                $url = $serverUrl.$fileName.'?check&version='.$version.'&sha1='.$sha1.'&minReplicas=0&token='.$this->configService->get('readerToken'); // &minReplicas=0  otherwise loopOfDeath
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