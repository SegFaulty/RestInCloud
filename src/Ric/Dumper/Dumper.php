<?php

class Ric_Dumper_Dumper {

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
				$msg = self::getHelp($cli->getArgument(2), $helpString);
			}else{
				switch($resourceType){
					case 'file':
						if( $mode=='dump' ){
							$msg = self::dumpFile($cli);
						}else{
							$msg = self::restoreFile($cli);
						}
						break;
					case 'mysql':
						if( $mode=='dump' ){
							$msg = self::dumpMysql($cli);
						}else{
							throw new RuntimeException('not implemeted yet');
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
	static protected function getHelp($command='', $helpString){
		if( $command and preg_match('~\n## Help '.preg_quote($command, '~').'(.*?)(\n## |$)~s', $helpString, $matches) ){
			$helpString = $matches[1];
		}
		if( $command=='' AND defined('BUILD_DATE') ){
			$helpString = 'build: '.BUILD_DATE.PHP_EOL.$helpString;
		}
		return $helpString;
	}

	/**
	 * @param Ric_Client_Cli $cli
	 * @return string
	 * @throws RuntimeException
	 */
	static protected function dumpFile($cli){
		$cli->getArgumentCount(3, 4);
		$resourceFilePath = $cli->getArgument(3);
		if( !is_file($resourceFilePath) ){
			throw new RuntimeException('source file not found: '.$resourceFilePath);
		}
		$command = 'cat '.$resourceFilePath;
		$command .= self::getCompressionCommand($cli);
		$command .= self::getEncryptionCommand($cli);
		$command .= self::getDumpFileForDumpCommand($cli);

		return self::executeCommand($cli, $command);
	}

	/**
	 * @param Ric_Client_Cli $cli
	 * @return string
	 * @throws RuntimeException
	 */
	static protected function restoreFile($cli){
		$cli->getArgumentCount(4, 4);
		$resourceFilePath = $cli->getArgument(3);
		if( is_file($resourceFilePath) AND !$cli->getOption('force') ){
			throw new RuntimeException('restore file already exists: '.$resourceFilePath.' use --force to overwrite');
		}
		$dumpFilePath = self::getDumpFileForRestore($cli);
		$command = 'cat '.$dumpFilePath;
		$command .= self::getDecryptionCommand($cli);
		$command .= self::getDecompressionCommand($cli);
		$command .= ' > '.$resourceFilePath;

		return self::executeCommand($cli, $command);
	}

	/**
	 * @param Ric_Client_Cli $cli
	 * @return string
	 * @throws RuntimeException
	 */
	static protected function dumpMysql($cli){
		$cli->getArgumentCount(3, 4);
		$resourceString = $cli->getArgument(3);
		list($user, $pass, $host, $port, $database, $tablePattern) = self::parseMysqlResourceString($resourceString);
		$mysqlDefaultFile = $cli->getOption('mysqlDefaultFile','');
		$tableList = [];
		if( $tablePattern!='' ){
			$tableList = explode(',', $tablePattern);
			foreach( $tableList as $tableEntry ){
				if( strstr($tableEntry, '*') ){
					throw new RuntimeException('table pattern with * is not implemented yet');
					// todo read tables with patterns
				}
			}
			$tableList = array_unique($tableList);
		}
		$mysqlDumpCommand = 'mysqldump';
		if( $mysqlDefaultFile!='' ){
			$mysqlDumpCommand .= ' --defaults-file='.$mysqlDefaultFile;
		}
		if( $host!='' ){
			$mysqlDumpCommand .= ' -h '.$host;
		}
		if( $port!='' AND $port!=3306 ){
			$mysqlDumpCommand .= ' -P '.$port;
		}
		if( $user!='' ){
			$mysqlDumpCommand .= ' -u '.$user;
		}
		if( $pass!='' ){
			$mysqlDumpCommand .= ' -p'.$pass;
		}
		$mysqlDumpCommand .= ' '.$database;
		$mysqlDumpCommand .= ' '.join(' ', $tableList);
		$command = $mysqlDumpCommand;
		$command .= self::getCompressionCommand($cli);
		$command .= self::getEncryptionCommand($cli);
		$command .= self::getDumpFileForDumpCommand($cli);

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
		if( $cli->getOption('test') ){
			$output = $command;
		}else{
			$targetFileName = $cli->getArgument(4, '');
			if( $targetFileName!='' AND $targetFileName!='STDOUT' ){
				exec($command, $output, $status);
				$output = implode("\n", $output);
			}else{
				// pump result to STDOUT
				passthru($command, $status);
				$output = '';
			}
			if( $status!=0 ){
				throw new RuntimeException('encryption failed: '.$command.' with: '.$output, 500);
			}
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
			$command = '| xz-6 -c'; // xz
		}elseif( $compressionMode=='extreme' ){
			$command = '| xz-9 -e -c'; // xz
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
			$command = '| xz-d'; // xz
		}elseif( $compressionMode=='extreme' ){
			$command = '| xz-d '; // xz
		}else{
			$command = '| bzip2 -d';
		}
		return $command;
	}

	/**
	 * @param Ric_Client_Cli $cli
	 * @return string
	 */
	protected static function getDumpFileForDumpCommand($cli){
		$command = '';
		$targetFileName = $cli->getArgument(4, '');
		if( $targetFileName!='' AND $targetFileName!='STDOUT' ){
			$targetFilePath = $cli->getOption('prefix', '').$targetFileName;
			$command .= ' > ';
			if( file_exists($targetFilePath) AND !$cli->getOption('force') ){
				throw new RuntimeException('target file already exists: '.$targetFilePath.' use --force to overwrite');
			}
			$command .= $targetFilePath;
		}
		return $command;
	}

	/**
	 * @param Ric_Client_Cli $cli
	 * @return string
	 */
	protected static function getDumpFileForRestore($cli){
		$targetFileName = $cli->getArgument(4);
		$targetFilePath = $cli->getOption('prefix', '').$targetFileName;
		if( !file_exists($targetFilePath) ){
			throw new RuntimeException('dump file not found: '.$targetFilePath);
		}
		return $targetFilePath;
	}

	/**
	 * // [{user}:{pass}@][{server}[:{port}]]/{dataBase}[/{tableNamePattern}]
	 * @param $resourceString
	 * @return array
	 */
	protected static function parseMysqlResourceString($resourceString){
		$passPattern = '[^ :]+';
		$userPattern = '[^ @]+';
		$dbPattern = '[^ /]+';
		$tableListPattern = '.+';
		if( !preg_match('~(?:('.$userPattern.'):('.$passPattern.')?@)?(?:([\w\.]+)(?::(\d+))?)?/('.$dbPattern.')(?:/('.$tableListPattern.'))?~', $resourceString, $matches) ){
			throw new RuntimeException('resource string not valid: '.$resourceString);
		}
		$user = $matches[1];
		$pass = $matches[2];
		$server = $matches[3];
		$port = intval(($matches[4] ? $matches[4] : 3306));
		$database = $matches[5];
		$tablePattern = (isset($matches[6]) ? $matches[6] : '');
		return array($user, $pass, $server, $port, $database, $tablePattern);
	}

	protected static function handleMysqlDebianCnf($mysqlDebianCnf, $user, $pass, $server){
		parse_ini_file("sample.ini", TRUE);
		return [$user, $pass, $server];
	}
}