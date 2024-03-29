<?php

class Ric_Server_ConfigManager {

	protected $defaultConfig = [
			'hostPort'         => '', // if empty use autoDetectSource host with default port
			'storeDir'         => '/nonExistingDir/ric/',
			'quota'            => 0,
			'servers'          => [],
			'adminToken'       => 'admin',
			'writerToken'      => 'writer',
			'readerToken'      => '',
			'defaultRetention' => Ric_Server_Definition::RETENTION__LAST3,
			'serverId'         => '',
			'logDir'           => '', // if empty no log is written
			'logLevel'         => 'warn', // debug,info,warn,error
			'logRetentionDays' => '10', // days to keep the daily logfiles
	];
	protected $config = [];

	/**
	 * Ric_Server_ConfigManager constructor.
	 */
	public function __construct($configFilePath){
		$this->loadConfig($configFilePath);
	}

	/**
	 * @return array
	 */
	public function getConfig(){
		return $this->config;
	}

	/**
	 * @return array
	 */
	public function getDefaultConfig(){
		return $this->defaultConfig;
	}

	/**
	 * load config default -> given config -> docRoot/intern/config.json
	 * @param string $configFilePath
	 * @return array
	 * @throws RuntimeException
	 */
	protected function loadConfig($configFilePath){

		if( !file_exists($configFilePath) ){
			throw new RuntimeException('config file not found: '.$configFilePath);
		}
		$localConfig = json_decode(file_get_contents($configFilePath), true);
		if( !is_array($localConfig) ){
			throw new RuntimeException('config file is invalid: '.$configFilePath);
		}
		$this->config = $localConfig + $this->config;

		$this->config = $this->config + $this->defaultConfig;

		$this->config['storeDir'] = rtrim($this->config['storeDir'], DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR; // make sure of trailing slash
		if( file_exists($this->config['storeDir'].'/intern/config.json') ){
			$this->config = json_decode(file_get_contents($this->config['storeDir'].'/intern/config.json'), true) + $this->config;
		}
		return $this->config;
	}

	/**
	 * @param string $key
	 * @return mixed
	 * @throws RuntimeException
	 */
	public function getValue($key){
		if( !isset($this->config[$key]) ){
			throw new RuntimeException('Config not found: '.$key);
		}
		return $this->config[$key];
	}

	/**
	 * set, update, remove (null) a value in runtimeConfig (and config)
	 * @param string $key
	 * @param mixed $value
	 */
	public function setRuntimeValue($key, $value){
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