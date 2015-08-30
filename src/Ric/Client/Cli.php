<?php

class Ric_Client_Cli {

	public $scriptName = '';
	public $arguments = [];
	public $options = [];
	public $configFileOptions = [];
	public $env = [];

	/**
	 * @param array $argv
	 * @param $env
	 * @param string $envPrefix
	 * @param string $configFilePath
	 */
	public function __construct($argv, $env, $envPrefix = 'ric', $configFilePath = ''){
		$this->scriptName = array_shift($argv);
		while( ($arg = array_shift($argv))!==null ){
			if( substr($arg, 0, 2)==='--' ){// Is it a option? (prefixed with --)
				$option = substr($arg, 2);
				if( strpos($option, '=')!==false ){// is it the syntax '--option=argument'?
					list($key, $value) = explode('=', $option, 2);
					$this->options[$key] = $value;
				}else{
					$this->options[$option] = true;
				}
			}elseif( substr($arg, 0, 1)==='-' ){ // Is it a flag or a serial of flags? (prefixed with -)
				for( $i = 1; isset($arg[$i]); $i++ ){
					$this->options[$arg[$i]] = true;
				}
			}else{
				$this->arguments[] = $arg;// finally, it is not option, nor flag
			}
		}
		// env
		foreach( $env as $key => $value ){
			if( preg_match('~^'.$envPrefix.'([A-Z].+)$~', $key, $matches) ){
				$this->env[lcfirst($matches[1])] = $value;
			}
		}
		$this->loadConfigFile($configFilePath);
	}

	/**
	 * @param string $configFilePath
	 * @throws RuntimeException
	 */
	public function loadConfigFile($configFilePath){
		if( $configFilePath!='' ){
			if( !file_exists($configFilePath) ){
				throw new RuntimeException('config file not found: '.$configFilePath);
			}
			foreach( file($configFilePath) as $index => $configLine ){
				$configLine = trim($configLine);
				if( $configLine=='' OR substr($configLine, 0, 1)=='#' OR substr($configLine, 0, 1)=='//' ){
					continue;
				}
				if( !preg_match('~^(\w+):\s*(.*)~', $configLine, $matches) ){
					throw new RuntimeException('invalid config at line: '.($index + 1).' "'.$configLine.'"');
				}
				$this->configFileOptions[$matches[1]] = $matches[2];
			}
		}
	}

	/**
	 * @param int $argumentPosition // starts with 1 !!!
	 * @param string|null $default
	 * @return null
	 */
	public function getArgument($argumentPosition = 1, $default = null){
		$argsIndex = $argumentPosition - 1;
		return isset($this->arguments[$argsIndex]) ? $this->arguments[$argsIndex] : $default;
	}

	/**
	 * return option > configFile > env > default
	 * @param string $name
	 * @param string $default
	 * @return string
	 */
	public function getOption($name, $default = null){
		$return = $default;
		if( array_key_exists($name, $this->options) ){
			$return = $this->options[$name];
		}elseif( array_key_exists($name, $this->configFileOptions) ){
			$return = $this->configFileOptions[$name];
		}elseif( array_key_exists($name, $this->env) ){
			$return = $this->env[$name];
		}
		return $return;
	}
}