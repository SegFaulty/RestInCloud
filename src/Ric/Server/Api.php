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
                $this->handlePutRequest();
            }elseif( $_SERVER['REQUEST_METHOD']=='POST' OR H::getRP('method')=='post' ){
                $this->handlePostRequest();
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
    protected function handlePostRequest(){
        $this->auth(Ric_Server_Auth_Definition::ROLE__WRITER);
        $action = H::getRP('action');
        if( parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)=='/' ){ // homepage
            // post actions
            if( $action=='addServer' AND $this->auth(Ric_Server_Auth_Definition::ROLE__ADMIN) ){
                $this->actionAddServer();
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
            $this->actionPostRefresh();
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
        $this->server->saveFileInCloud($tmpFilePath, $fileName, $retention, $timestamp, $noSync);
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
     * check and refresh a with a post request
     * @throws RuntimeException
     */
    protected function actionPostRefresh(){
        $version = H::getRP('sha1');
        $retention = H::getRP('retention', '');
        $timestamp = H::getRP('timestamp', time());
        $noSync = (bool) H::getRP('noSync');

        if( $version=='' ){
            throw new RuntimeException('?sha1=1342.. is required', 400);
        }
        $fileName = $this->extractFileNameFromRequest();
        $this->server->refreshFile($fileName, $version, $retention, $timestamp, $noSync);
    }

    /**
     * @throws RuntimeException
     */
    protected function actionAddServer(){
        $server = H::getRP('addServer');
        $this->server->addServer($server);
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