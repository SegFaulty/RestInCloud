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
        $this->server->handleRequest();
    }
}