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
                $this->server->handleGetRequest();
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