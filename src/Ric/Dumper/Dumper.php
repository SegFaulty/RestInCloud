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
		$cli = new Ric_Client_Cli($argv, $env, 'dumper');
		$cli->loadConfigFile($cli->getOption('configFile')); // load config file if present
		if( $cli->getOption('verbose') ){
			$msg.= $cli->dumpParameters();
		}

		$mode = $cli->getArgument(1);
		$resourceType = $cli->getArgument(2);

		try{
			if( !in_array($mode, ['dump', 'restore', 'help']) ){
				throw new RuntimeException(' first arguments needs to be help, dump or restore'.PHP_EOL);
			}
			if( $mode=='help' ){
				$msg.= self::getHelp($cli->getArgument(2), $helpString);
			}else{
				switch($resourceType){
					case 'std':
						if( $mode=='dump' ){
							$msg.= self::dumpStd($cli);
						}else{
							$msg.= self::restoreStd($cli);
						}
						break;
					case 'file':
						if( $mode=='dump' ){
							$msg.= self::dumpFile($cli);
						}else{
							$msg.= self::restoreFile($cli);
						}
						break;
					case 'dir':
						if( $mode=='dump' ){
							$msg.= self::dumpDir($cli);
						}else{
							$msg.= self::restoreDir($cli);
						}
						break;
					case 'mysql':
						if( $mode=='dump' ){
							$msg.= self::dumpMysql($cli);
						}else{
							$msg.= self::restoreMysql($cli);
						}
						break;
					case 'influxdb':
						if( $mode=='dump' ){
							$msg .= self::dumpInfluxDb($cli);
						}else{
							$msg .= self::restoreInfluxDb($cli);
						}
						break;
					case '':
						throw new RuntimeException('second arg needs to be a resource type - see usage'.PHP_EOL);
					default:
						throw new RuntimeException('unknown resource type: '.$resourceType.PHP_EOL);

				}
			}
		}catch(Exception $e){
			$status = 1;
			static::stdErr(trim('ERROR: '.$e->getMessage()).PHP_EOL);
		}

		if( !$cli->getOption('quite') ){
			if( trim($msg)!='' ){
				static::stdOut(rtrim($msg).PHP_EOL); // add trailing newline
			}
		}
		if( is_bool($status) ){
			$status = (int) !$status;
		}
		return $status; // success -> 0 , failed >= 1
	}

	/**
	 * @param string $msg
	 */
	static protected function stdOut($msg){
		echo $msg;
	}

	/**
	 * @param string $msg
	 */
	static protected function stdErr($msg){
		ini_set('display_errors', 'stderr'); // ensure we write to STDERR
		fwrite(STDERR, $msg);
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
	static protected function getHelp($command = '', $helpString){
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
	static protected function dumpStd($cli){
		// todo experimental not checked
		$cli->getArgumentCount(3, 4);
		$resourceFilePath = $cli->getArgument(3);
		if( $resourceFilePath!='STDIN' ){
			throw new RuntimeException('source must be "STDIN" ');
		}
		$command = 'cat';
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
	static protected function restoreStd($cli){
		// todo experimental not checked
		$dumpFilePath = self::getDumpFileForRestore($cli);
		$resourceFilePath = $cli->getArgument(3);
		if( $resourceFilePath!='STDIN' AND $resourceFilePath!='STDOUT' ){
			throw new RuntimeException('source must be "STDIN or STDOUT" ');
		}
		$command = 'cat '.$dumpFilePath;
		$command .= self::getDecryptionCommand($cli);
		$command .= self::getDecompressionCommand($cli);
		// geht nach stdout  da es

		return self::executeCommand($cli, $command);
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
		$command =  self::getPrefixDumpCommand($cli);
		$command .= 'cat '.$resourceFilePath;
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
		$dumpFilePath = self::getDumpFileForRestore($cli);
		$argCount = $cli->getArgumentCount(3, 4);
		if( $argCount==4 ){
			$resourceFilePath = $cli->getArgument(3);
		}else{
			$resourceFilePath = basename($dumpFilePath);
		}
		if( is_file($resourceFilePath) AND !$cli->getOption('force') ){
			throw new RuntimeException('restore file already exists: '.$resourceFilePath.' use --force to overwrite');
		}
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
	static protected function dumpDir($cli){
		$cli->getArgumentCount(3, 4);
		$resourceFilePath = $cli->getArgument(3);
		if( !is_dir($resourceFilePath) ){
			throw new RuntimeException('source dir not found: '.$resourceFilePath);
		}
		$command =  self::getPrefixDumpCommand($cli);
		$command .= 'tar -C '.$resourceFilePath.' -cp .'; // keep fileowners, we change to the given dir
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
	static protected function restoreDir($cli){
		$cli->getArgumentCount(3, 4);
		$command = '';
		$resourceFilePath = $cli->getArgument(3); // = restore dir
		if( !is_dir($resourceFilePath) ){
			$command .= 'mkdir -p '.$resourceFilePath.' && '; // create target dir if not exists
		}
		$dumpFilePath = self::getDumpFileForRestore($cli);
		$command .= 'cat '.$dumpFilePath;
		$command .= self::getDecryptionCommand($cli);
		$command .= self::getDecompressionCommand($cli);
		$command .= ' | tar -C '.$resourceFilePath.' -xp'; // change dir to target dir
		if( !$cli->getOption('force') ){
			$command .= " --keep-old-files"; // don't replace existing files when extracting, treat them as errors
		}
		$command .= " --atime-preserve"; // don't touch mtime and atime

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
		$mysqlDefaultFile = $cli->getOption('mysqlDefaultFile', '');
		$tableList = [];
		if( $tablePattern!='' ){
			foreach( explode(',', $tablePattern) as $tableEntry ){
				if( strstr($tableEntry, '*') ){
					// read tables with pattern from mysql
					$command = 'echo "SHOW TABLES LIKE \''.str_replace('*', '%', $tableEntry).'\';"';
					$command .= ' | '.self::getMysqlCommandString('mysql', $mysqlDefaultFile, $host, $port, $user, $pass, $database);
					$command .= ' --skip-column-names';
					exec($command, $output, $status);
					if( $status!==0 ){
						throw new RuntimeException('show tables ('.$command.') failed! :'.implode(';', $output));
					}
					$tableList = array_merge($tableList, $output);
				}else{
					$tableList[] = $tableEntry;
				}
			}
			$tableList = array_unique($tableList);
		}
		$command =  self::getPrefixDumpCommand($cli);
		$command .= self::getMysqlCommandString('mysqldump', $mysqlDefaultFile, $host, $port, $user, $pass, $database, $tableList);
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
	static protected function restoreMysql($cli){
		$cli->getArgumentCount(3, 4);
		$resourceString = $cli->getArgument(3);
		list($user, $pass, $host, $port, $database) = self::parseMysqlResourceString($resourceString);
		$mysqlDefaultFile = $cli->getOption('mysqlDefaultFile', '');

		$dumpFilePath = self::getDumpFileForRestore($cli);
		$command = 'cat '.$dumpFilePath;
		$command .= self::getDecryptionCommand($cli);
		$command .= self::getDecompressionCommand($cli);
		$command .= ' | '.self::getMysqlCommandString('mysql', $mysqlDefaultFile, $host, $port, $user, $pass, $database);

		return self::executeCommand($cli, $command);
	}

	/**
	 * @param Ric_Client_Cli $cli
	 * @return string
	 * @throws RuntimeException
	 */
	static protected function dumpInfluxDb($cli){
		$cli->getArgumentCount(3, 4);
		$resourceString = $cli->getArgument(3);
		if( $resourceString!='instance' ){
			// determine all databases
		}
		// influxd backup -database db /data/backup/influxdb

		$option = '';
		if( $resourceString=='instance' ){
			throw new RuntimeException('dump influxdb instance is not yet implemented');
		}elseif( $resourceString=='meta' ){
		}else{
			$option = ' -database '.$resourceString;
		}

		$command =  self::getPrefixDumpCommand($cli);
		$command .= 'tempDir=`/bin/mktemp -d` && /usr/bin/influxd backup'.$option.' $tempDir 2> /dev/null';
		$command .= ' && tar -C $tempDir -c .'; // we change to the given dir
		$command .= self::getCompressionCommand($cli);
		$command .= self::getEncryptionCommand($cli);
		$command .= self::getDumpFileForDumpCommand($cli);
// eher nicht so gut		$command .= ' && /bin/rm -rf $tempDir'; // hui jui jui

		return self::executeCommand($cli, $command);
	}

	/**
	 * @param Ric_Client_Cli $cli
	 * @return string
	 * @throws RuntimeException
	 */
	static protected function restoreInfluxDb($cli){
		$cli->getArgumentCount(3, 4);
//		$resourceString = $cli->getArgument(3);
//		list($user, $pass, $host, $port, $database) = self::parseMysqlResourceString($resourceString);
//		$mysqlDefaultFile = $cli->getOption('mysqlDefaultFile', '');
//
		$dumpFilePath = self::getDumpFileForRestore($cli);
		$command = 'cat '.$dumpFilePath;
//		$command .= self::getDecryptionCommand($cli);
//		$command .= self::getDecompressionCommand($cli);
//		$command .= ' | '.self::getMysqlCommandString('mysql', $mysqlDefaultFile, $host, $port, $user, $pass, $database);

		return self::executeCommand($cli, $command);
	}

	/**
	 * @param Ric_Client_Cli $cli
	 * @param string $command
	 * @return mixed
	 */
	static protected function executeCommand($cli, $command){
		if( $cli->getOption('verbose') ){
			static::stdOut($command.PHP_EOL);
		}
		if( $cli->getOption('test') ){
			$output = 'command: "'.$command.'"';
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
				throw new RuntimeException('command execution failed: '.$command, 500);
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
		// password
		$password = $cli->getOption('pass', self::resolveSecretFile($cli->getOption('passFile')));
		if( $password!='' ){
			$salt = '_sdffHGetdsga';
			$command = '| openssl enc -aes-256-cbc -S '.bin2hex(substr($salt, 0, 8)).' -k '.escapeshellarg((string) $password);
		}

		/*
				 # public key // inspired by https://www.devco.net/archives/2006/02/13/public_-_private_key_encryption_using_openssl.php
				 # create privatekey and cert ("-nodes" disabled password for privatekey)
				 openssl req -x509 -sha256 -days 10000 -newkey rsa:2048 -keyout backupEncryptionPrivateKey.pem -out backupEncryptionPubCert.pem -nodes -subj '/'
				 # use cert for encryption
				 openssl smime -encrypt -aes256 -binary -outform D -in phperror.log.bz2 -out phperror.log.bz2.der backupEncryptionPubCert.pem
				 # use privatKey to decryption
				 openssl smime -decrypt -inform D -binary -in phperror.log.bz2.der -inkey backupEncryptionPrivateKey.pem -out phperror.log.bz2.decrypted
				 # check cert (todo Validity Not After : Feb 22 22:03:54 2044 GMT ... sollte noch lange halten?!, aber ich glaube es laesst sich immer decrypten
				 openssl x509 -in backupEncryptionPubCert.pem -text
		*/
		$publicCert = $cli->getOption('publicCert');
		if( $publicCert!='' ){
			$command = '| openssl smime -encrypt -aes256 -binary -outform D '.escapeshellarg((string) $publicCert);
		}
		// deterministic asyncronous ancryption
		// 1. get sha1 of sourcefile
		// 2. encrypt the sha1 with openssl async encryption
		// 3. write the encrypted pw to targetfile  - we need a fixed len here ?!
		// 4. use this sha1 as password for openssl enc -aes-256-cbc with fixed salt and append target file
		// decrypt:
		// 1. read the fixed len enxrypted key
		// 2. decrypt with private key
		// 3. use the result als password for openssl enc -d -aes-256-cbc for the rest of the file
		return $command;
	}

	/**
	 * get decryption comand
	 * @param Ric_Client_Cli $cli
	 * @return string
	 */
	static protected function getDecryptionCommand($cli){
		$command = '';
		// password
		$password = $cli->getOption('pass', self::resolveSecretFile($cli->getOption('passFile')));
		if( $password!='' ){
			$salt = '_sdffHGetdsga';
			$command = '| openssl enc -d -aes-256-cbc -S '.bin2hex(substr($salt, 0, 8)).' -k '.escapeshellarg((string) $password);
		}
		// private key
		$privateKey = $cli->getOption('privateKey');
		if( $privateKey!='' ){
			$command = '| openssl smime -decrypt -inform D -binary -inkey '.escapeshellarg((string) $privateKey);
		}
		return $command;
	}

	/**
	 * get prefix commands
	 * @param Ric_Client_Cli $cli
	 * @return string
	 */
	static protected function getPrefixDumpCommand($cli){
		$command = '';
		$skipUmask = (bool) $cli->getOption('skipUmask', false);
		if( !$skipUmask ){
			$command .= 'umask 0077 && '; // set umask to  give only the current user read/write
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
			$command = ' | lzop -1'; // lzop
		}elseif( $compressionMode=='hard' ){
			$command = ' | xz -6 -c'; // xz
		}elseif( $compressionMode=='extreme' ){
			$command = ' | xz -9 -e -c'; // xz
		}else{
			$command = ' | bzip2 -9';
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
			$command = ' | lzop -d'; // lzop
		}elseif( $compressionMode=='hard' ){
			$command = ' | xz -d'; // xz
		}elseif( $compressionMode=='extreme' ){
			$command = ' | xz -d '; // xz
		}else{
			$command = ' | bzip2 -d';
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
			if( $cli->getOption('rotate') AND $cli->getOption('force') ){
				throw new RuntimeException('you can not use --force and --rotate on the same time');
			}
			if( file_exists($targetFilePath) ){
				if( $cli->getOption('rotate') ){
					$rotateCount = $cli->getOption('rotate', 0);
					if( $rotateCount===true ){
						$rotateCount = 3; //  if only "--rotate" we use default "--rotate=3"
					}
					self::rotateFile($targetFilePath, $rotateCount);
				}elseif( $cli->getOption('force') ){
					// overwrite
				}else{
					throw new RuntimeException('target file already exists: '.$targetFilePath.' use --force to overwrite or --rotate[=3]');
				}
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
		$targetFilePath = '';
		$argCount = $cli->getArgumentCount();
		$targetFileName = $cli->getArgument($argCount); // last arg is dumpFile
		if( $targetFileName!='STDOUT' ){ // if STDOUT dann empty $targetFilePath will end  STDIN like " cat | decription ...."
			$targetFilePath = $cli->getOption('prefix', '').$targetFileName;
			if( !file_exists($targetFilePath) ){
				throw new RuntimeException('dump file not found: '.$targetFilePath);
			}
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
		if( $server=='' ){
			$server = 'localhost';
		}
		$port = intval(($matches[4] ? $matches[4] : 3306));
		$database = $matches[5];
		$tablePattern = (isset($matches[6]) ? $matches[6] : '');
		return array($user, $pass, $server, $port, $database, $tablePattern);
	}

	/**
	 * @param string $mysqlCommand
	 * @param string $mysqlDefaultFile
	 * @param string $host
	 * @param int $port
	 * @param string $user
	 * @param string $pass
	 * @param string $database
	 * @param string[] $tableList
	 * @return string
	 */
	protected static function getMysqlCommandString($mysqlCommand, $mysqlDefaultFile, $host, $port, $user, $pass, $database, $tableList = []){
		if( $mysqlDefaultFile!='' ){
			$mysqlCommand .= ' --defaults-file='.$mysqlDefaultFile;
		}
		if( $host!='' ){
			$mysqlCommand .= ' -h '.$host;
		}
		if( $port!='' AND $port!=3306 ){
			$mysqlCommand .= ' -P '.$port;
		}
		if( $user!='' ){
			$mysqlCommand .= ' -u '.$user;
		}
		if( $pass!='' ){
			$mysqlCommand .= ' -p'.$pass;
		}
		$mysqlCommand .= ' '.$database;
		$mysqlCommand .= ' '.join(' ', $tableList);
		return trim($mysqlCommand);
	}

	/**
 	 * rotate file (if exists) and adds ".1" ".2" to rotated files
	 * returns rotated file count
	 * @param string $sourceFilePath
	 * @param int $maxRotationFiles
	 * @return int
	 */
	static protected function rotateFile($sourceFilePath, $maxRotationFiles){
		$rotatedFileCount = 0;
		if( file_exists($sourceFilePath) ){ // don't rotate if file not exists
			$filePaths = [$sourceFilePath];
			for( $i = 1; $i<=$maxRotationFiles; $i++ ){
				$filePaths[] = $sourceFilePath.'.'.$i;
			}
			$filePaths = array_reverse($filePaths);
			foreach( $filePaths as $index => $filePath ){
				if( $index<$maxRotationFiles ){
					if( file_exists($filePaths[$index+1]) AND ($index==$maxRotationFiles-1 OR file_exists($filePaths[$index+2])) ){ // nur rotieren wenn auch das file davor existier oder es das source file sit
						if( !rename($filePaths[$index+1], $filePath) ){
							throw new RuntimeException('rename '.$filePaths[$index-1].' to '.$filePath.' failed!');
						}
						$rotatedFileCount++;
					}
				}
			}
		}
		return $rotatedFileCount;
	}
}