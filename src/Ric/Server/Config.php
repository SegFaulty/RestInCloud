<?php

class Ric_Server_Config{
    protected $defaultConfig = [];

    protected $config = [];

    /**
     * Ric_Server_Config constructor.
     * @param array $defaultConfig
     */
    public function __construct(array $defaultConfig)
    {
        $this->defaultConfig = $defaultConfig;
    }

    /**
     * load config default->given config -> docRoot/intern/config.json
     */
    public function loadConfig($configFilePath=''){

        if( file_exists($configFilePath) ){
            $localConfig = json_decode(file_get_contents($configFilePath), true);
            if( !is_array($localConfig) ){
                throw new RuntimeException('config.json is invalid (use "{}" for empty config)');
            }
            $this->config = $localConfig + $this->config;
        }

        $this->config = $this->config + $this->defaultConfig;

        $this->config['storeDir'] = rtrim($this->config['storeDir'],DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR; // make sure of trailing slash

        if( file_exists($this->config['storeDir'].'/intern/config.json') ){
            $this->config = json_decode(file_get_contents($this->config['storeDir'].'/intern/config.json'), true) + $this->config;
        }
        return $this->config;
    }

    /**
     * set, update, remove (null) a value in runtimeConfig (and config)
     * @param string $key
     * @param string $value
     */
    public function setRuntimeConfig($key, $value){
        $runtimeConfig = [];
        if( file_exists($this->config['storeDir'].'/intern/config.json') ){
            $runtimeConfig = json_decode(file_get_contents($this->config['storeDir'].'/intern/config.json'), true);
        }
        if( $value===null ){
            if( isset($runtimeConfig[$key]) ){
                unset($runtimeConfig[$key]);
            }
        }else{
            $runtimeConfig[$key] = $value;
            $this->config[$key] = $value;
        }
        if( !is_dir($this->config['storeDir'].'/intern/') ){
            mkdir($this->config['storeDir'].'/intern/');
        }
        file_put_contents($this->config['storeDir'].'/intern/config.json', H::json($runtimeConfig));
    }
}