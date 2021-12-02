<?php

/**
 * Class Ric_Server_Logger
 */
class Ric_Server_Logger {

	/**
	 * @var Ric_Server_ConfigManager
	 */
	protected $configManager;
	protected $msgPrefix = '';

	/**
	 * construct
	 * @param Ric_Server_ConfigManager $configManager
	 * @throws RuntimeException
	 */
	public function __construct($configManager, $msgPrefix = ''){
		$this->configManager = $configManager;
		$this->msgPrefix = $msgPrefix;
	}

	/**
	 * @param string $msg
	 */
	public function debug($msg){
		$this->log('debug', $msg);
	}

	/**
	 * @param string $msg
	 */
	public function info($msg){
		$this->log('info', $msg);
	}

	/**
	 * @param string $msg
	 */
	public function warn($msg){
		$this->log('warn', $msg);
	}

	/**
	 * @param string $msg
	 */
	public function error($msg){
		$this->log('error', $msg);
	}

	/**
	 * @param string $level
	 * @param string $message
	 */
	protected function log($level, $message){
		$logLevels = [
				'debug' => 4,
				'info'  => 3,
				'warn'  => 2,
				'error' => 1,
		];
		$logDir = $this->configManager->getValue('logDir');
		if( $logDir ){ // else no logging
			if( $logLevels[$this->configManager->getValue('logLevel')]>=$logLevels[$level] ){
				$logDir = rtrim($logDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
				$baseFileName = $logDir.'ricServerLog-'.$this->configManager->getValue('serverId');
				$logfilePath = $baseFileName.'-'.date('Ymd').'.log';
				$message = $this->msgPrefix.'['.$level.']'.$message;
				$garbageCollectionDeletedFiles = null;
				if( !file_exists($logfilePath) ){ // a new day, lets start the garbage collection
					// garbage collection
					$retentionTimestamp = time() - 86400 * max(1, $this->configManager->getValue('logRetentionDays'));
					foreach( glob($baseFileName.'*') as $fileName ){
						$filePath = $logDir.$fileName;
						if( filemtime($filePath)<$retentionTimestamp ){
							unlink($filePath);
							$garbageCollectionDeletedFiles++;
						}
					}
				}
				if( $garbageCollectionDeletedFiles!==null and $this->configManager->getValue('logLevel')=='debug' ){
					$message = $this->msgPrefix.'[debug]'.'dailyGarbageCollection deleted logFiles: '.$garbageCollectionDeletedFiles."\n".$message;
				}
				file_put_contents($logfilePath, $message."\n", FILE_APPEND);
			} // loglevel not reached
		} // no logging
	}
}
