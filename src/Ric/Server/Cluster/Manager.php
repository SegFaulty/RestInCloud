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
}