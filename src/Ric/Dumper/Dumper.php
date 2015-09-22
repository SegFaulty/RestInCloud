<?php

class Ric_Dumper {

	/**
	 * @param array $argv
	 * @param array $env
	 * @param string $helpString
	 * @return int
	 */
	static public function handleExecute($argv, $env, $helpString){
		$status = true;
		$msg = '';
		$cli = new Ric_Client_Cli($argv, $env);
		$cli->loadConfigFile($cli->getOption('configFile')); // load config file if present
		$mode = $cli->getArgument(1);
		$resourceType = $cli->getArgument(2);

		try{
			if( !in_array($mode, ['dump', 'restore', 'help']) ){
				throw new RuntimeException(' first arguments needs to be help, dump or restore'.PHP_EOL);
			}
			if( $mode=='help' ){
				$msg = self::getHelp(reset($cli->arguments), $helpString);
			}else{
				switch($resourceType){
					case 'file':
						if( $mode=='dump' ){
							$msg = self::dumpFile($cli);
						}else{
							$msg = self::restoreFile($cli);
						}
						break;
					default:
						throw new RuntimeException('unknown resource type: '.$resourceType.PHP_EOL);

				}
			}
		}catch(Exception $e){
			$status = 1;
			ini_set('display_errors', 'stderr'); // ensure we write to STDERR
			fwrite(STDERR, trim('ERROR: '.$e->getMessage()).PHP_EOL);
		}

		if( !$cli->getOption('quite') ){
			echo rtrim($msg).PHP_EOL; // add trailing newline
		}
		if( is_bool($status) ){
			$status = (int) !$status;
		}
		return $status; // success -> 0 , failed >= 1
	}

	/**
	 * @param string $filePath
	 * @throws RuntimeException
	 * @return string
	 */
	static protected function resolveSecretFile($filePath){
		$secret = '';
		if( $filePath!='' ){
			if( !file_exists($filePath) OR !is_readable($filePath) ){
				throw new RuntimeException('authFile not found or not readable: '.$filePath);
			}
			$secret = trim(file_get_contents($filePath));
		}
		return $secret;
	}

	/**
	 * @param string $command
	 * @param string $helpString
	 * @return string
	 */
	static protected function getHelp($command = 'global', $helpString){
		if( $command and preg_match('~\n## Help '.preg_quote($command, '~').'(.*?)(\n## |$)~s', $helpString, $matches) ){
			$helpString = $matches[1];
		}
		return $helpString;
	}

	/**
	 * @param Ric_Client_Cli $cli
	 * @return string
	 * @throws RuntimeException
	 */
	static protected function dumpFile($cli){
		$fileName = $cli->getArgument(3);
		if( $fileName=='' ){
			throw new RuntimeException('source file missing');
		}
		$sourceFilePath = $fileName;
		if( !is_file($sourceFilePath) ){
			throw new RuntimeException('source file not found: '.$sourceFilePath);
		}
		$targetFileName = $cli->getArgument(4);
		if( $targetFileName=='' ){
			throw new RuntimeException('target file missing');
		}
		$targetFilePath = $cli->getOption('prefix', '').$targetFileName;
		if( file_exists($targetFilePath) ){
			throw new RuntimeException('target file already exists: '.$targetFilePath);
		}
		$command = 'cat '.$sourceFilePath;
		$command .= self::getCompressionCommand($cli);
		$command .= self::getEncryptionCommand($cli);
		$command .= ' > '.$targetFilePath;

		return self::executeCommand($cli, $command);
	}

	/**
	 * @param Ric_Client_Cli $cli
	 * @return string
	 * @throws RuntimeException
	 */
	static protected function restoreFile($cli){
		$fileName = $cli->getArgument(3);
		if( $fileName=='' ){
			throw new RuntimeException('restore file missing');
		}
		$sourceFilePath = $fileName;
		if( is_file($sourceFilePath) ){
			throw new RuntimeException('restore file already exists: '.$sourceFilePath);
		}
		$targetFileName = $cli->getArgument(4);
		if( $targetFileName=='' ){
			throw new RuntimeException('dump file missing');
		}
		$targetFilePath = $cli->getOption('prefix', '').$targetFileName;
		if( !file_exists($targetFilePath) ){
			throw new RuntimeException('dump file not found: '.$targetFilePath);
		}
		$command = 'cat '.$targetFilePath;
		$command .= self::getDecryptionCommand($cli);
		$command .= self::getDecompressionCommand($cli);
		$command .= ' > '.$sourceFilePath;

		return self::executeCommand($cli, $command);
	}

	/**
	 * @param Ric_Client_Cli $cli
	 * @param string $command
	 * @return mixed
	 */
	static protected function executeCommand($cli, $command){
		if( $cli->getOption('verbose') ){
			echo $command.PHP_EOL;
		}
		exec($command, $output, $status);
		$output = implode("\n", $output);
		if( $status!=0 ){
			throw new RuntimeException('encryption failed: '.$command.' with: '.$output, 500);
		}
		return $output;
	}

	/**
	 * get encryption comand
	 * @param Ric_Client_Cli $cli
	 * @return string
	 */
	static protected function getEncryptionCommand($cli){
		$command = '';
		$password = $cli->getOption('pass', self::resolveSecretFile($cli->getOption('passFile')));
		if( $password!='' ){
			$salt = '_sdffHGetdsga';
			$command = '| openssl enc -aes-256-cbc -S '.bin2hex(substr($salt, 0, 8)).' -k '.escapeshellarg((string) $password);
		}
		return $command;
	}

	/**
	 * get decryption comand
	 * @param Ric_Client_Cli $cli
	 * @return string
	 */
	static protected function getDecryptionCommand($cli){
		$command = '';
		$password = $cli->getOption('pass', self::resolveSecretFile($cli->getOption('passFile')));
		if( $password!='' ){
			$salt = '_sdffHGetdsga';
			$command = '| openssl enc -d -aes-256-cbc -S '.bin2hex(substr($salt, 0, 8)).' -k '.escapeshellarg((string) $password);
		}
		return $command;
	}

	/**
	 * get compression command
	 * @param Ric_Client_Cli $cli
	 * @return string
	 */
	static protected function getCompressionCommand($cli){
		$compressionMode = $cli->getOption('compress', 'bzip2');
		if( $compressionMode=='off' ){
			$command = ''; // nothing
		}elseif( $compressionMode=='fast' ){
			$command = '| lzop -1'; // lzop
		}elseif( $compressionMode=='hard' ){
			$command = '| ex -6 -c'; // ex
		}elseif( $compressionMode=='extreme' ){
			$command = '| ex -9 -e -c'; // ex
		}else{
			$command = '| bzip2 -9';
		}
		return $command;
	}

	/**
	 * get decompression command
	 * @param Ric_Client_Cli $cli
	 * @return string
	 */
	static protected function getDecompressionCommand($cli){
		$compressionMode = $cli->getOption('compress', 'bzip2');
		if( $compressionMode=='off' ){
			$command = ''; // nothing
		}elseif( $compressionMode=='fast' ){
			$command = '| lzop -d'; // lzop
		}elseif( $compressionMode=='hard' ){
			$command = '| ex -d'; // ex
		}elseif( $compressionMode=='extreme' ){
			$command = '| ex -d '; // ex
		}else{
			$command = '| bzip2 -d';
		}
		return $command;
	}
}