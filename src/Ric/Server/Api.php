<?php

class Ric_Server_Api{
    /**
     * @var Ric_Server_Server
     */
    protected $server;

    /**
     * @var Ric_Server_Auth_Service
     */
    protected $authService;

    /**
     * Ric_Server_Api constructor.
     * @param Ric_Server_Server $server
     * @param Ric_Server_Auth_Service $authService
     */
    public function __construct(Ric_Server_Server $server, Ric_Server_Auth_Service $authService)
    {
        $this->server = $server;
        $this->authService = $authService;
    }

    /**
     * handle GETs
     */
    public function handleRequest(){
        try{
            $this->auth(Ric_Server_Auth_Definition::ROLE__READER, true);
            if( $_SERVER['REQUEST_METHOD']=='PUT' ){
                $this->server->handlePutRequest();
            }elseif( $_SERVER['REQUEST_METHOD']=='POST' OR H::getRP('method')=='post' ){
                $this->server->handlePostRequest();
            }elseif( $_SERVER['REQUEST_METHOD']=='GET' ){
                $this->handleGetRequest();
            }elseif( $_SERVER['REQUEST_METHOD']=='DELETE' OR H::getRP('method')=='delete' ){
                if( $this->auth(Ric_Server_Auth_Definition::ROLE__WRITER) ){
                    $this->server->actionDelete();
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
     * handle get
     */
    protected function handleGetRequest(){
        $action = '';
        if( preg_match('~^(\w+).*~', H::getIKS($_SERVER, 'QUERY_STRING'), $matches) ){
            $action = $matches[1];
        }
        if( $_SERVER['REQUEST_URI']=='/' ){ // homepage
            $this->server->actionHelp();
        }elseif( parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)=='/' ){ // no file
            if( $action=='list' AND $this->auth(Ric_Server_Auth_Definition::ROLE__ADMIN) ){
                $this->server->actionList();
            }elseif( $action=='listDetails' AND $this->auth(Ric_Server_Auth_Definition::ROLE__ADMIN) ){
                $this->server->actionList(true);
            }elseif( $action=='help' ){
                $this->server->actionHelp();
            }elseif( $action=='info' ){
                $this->server->actionInfo();
            }elseif( $action=='health' ){
                $this->server->actionHealth();
            }elseif( $action=='phpInfo' ){
                phpinfo();
            }else{
                throw new RuntimeException('unknown action', 400);
            }
        }elseif( $action=='size' ){
            $this->server->actionGetFileSize();
        }elseif( $action=='check' ){
            $this->server->actionCheck();
        }elseif( $action=='list' ){
            $this->server->actionListVersions();
        }elseif( $action=='head' ){
            $this->server->actionHead();
        }elseif( $action=='grep' ){
            $this->server->actionGrep();
        }elseif( $action=='help' ){
            $this->server->actionHelp();
        }elseif( $action=='' ){
            $this->server->actionSendFile();
        }else{
            throw new RuntimeException('unknown action', 400);
        }
    }

    /**
     * handle POST, file refresh
     */
    public function handlePostRequest(){
        $this->auth(Ric_Server_Auth_Definition::ROLE__WRITER);
        $action = H::getRP('action');
        if( parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)=='/' ){ // homepage
            // post actions
            if( $action=='addServer' AND $this->auth(Ric_Server_Auth_Definition::ROLE__ADMIN) ){
                $this->server->actionAddServer();
            }elseif( $action=='removeServer' AND $this->auth(Ric_Server_Auth_Definition::ROLE__ADMIN) ){
                $this->server->actionRemoveServer();
            }elseif( $action=='joinCluster' AND $this->auth(Ric_Server_Auth_Definition::ROLE__ADMIN) ){
                $this->server->actionJoinCluster();
            }elseif( $action=='leaveCluster' AND $this->auth(Ric_Server_Auth_Definition::ROLE__ADMIN) ){
                $this->server->actionLeaveCluster();
            }elseif( $action=='removeFromCluster' AND $this->auth(Ric_Server_Auth_Definition::ROLE__ADMIN) ){
                $this->server->actionRemoveFromCluster();
            }else{
                throw new RuntimeException('unknown action or no file given [Post]', 400);
            }
        }else{
            // not "/" .. this is a file, refresh action
            $this->server->actionPostRefresh();
        }
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
}