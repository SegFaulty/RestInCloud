<?php

class Ric_Dumper_Dumper {

	const VERSION = '0.4.0';

	const SALT = '_sdffHGe'; // simple fixed salt for deterministic encryption

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
	static protected function checkSecretFile($filePath){
		$secret = '';
		if( $filePath!='' ){
			if( !file_exists($filePath) OR !is_readable($filePath) ){
				throw new RuntimeException('authFile not found or not readable: '.$filePath);
			}
		}
		return $secret;
	}

	/**
	 * @param string $command
	 * @param string $helpString
	 * @return string
	 */
	static protected function getHelp($command = '', $helpString){
		if( $command and preg_match('~\n#+ Help '.preg_quote($command, '~').'(.*?)(\n#+ |$)~si', $helpString, $matches) ){
			$helpString = $matches[1];
		}
		if( $command=='' AND defined('BUILD_DATE') ){
			$helpString = ' v'.self::VERSION.' build: '.BUILD_DATE.PHP_EOL.$helpString;

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
		$command .= self::getDecryptionCommand($cli, $dumpFilePath);
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
		$command .= self::getDecryptionCommand($cli, $dumpFilePath);
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
		$excludes = array_filter(explode('|', $cli->getOption('exclude', '')));
		$command = self::getPrefixDumpCommand($cli);
		$command .= 'tar -C '.$resourceFilePath.' -cp .'; // keep fileowners, we change to the given dir
		if( $excludes ){
			$command .= ' --exclude='.implode(' --exclude=', $excludes);
		}
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
		$command .= self::getDecryptionCommand($cli, $dumpFilePath);
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
				$tables = [];
				$exclude = false;
				if( substr($tableEntry, 0, 1)=='-' ){
					$exclude = true;
					$tableEntry = substr($tableEntry, 1);
				}
				if( strstr($tableEntry, '*') ){
					// read tables with pattern from mysql
					$command = 'echo "SHOW TABLES LIKE \''.str_replace('*', '%', $tableEntry).'\';"';
					$command .= ' | '.self::getMysqlCommandString('mysql', $mysqlDefaultFile, $host, $port, $user, $pass, $database);
					$command .= ' --skip-column-names';
					$output = null;
					exec($command, $output, $status);
					if( $status!==0 ){
						throw new RuntimeException('show tables ('.$command.') failed! :'.implode(';', $output));
					}
					$tables = $output;
				}else{
					$tables[] = $tableEntry;
				}
				if( $exclude ){
					$tableList = array_diff($tableList, $tables);
				}else{
					$tableList = array_merge($tableList, $tables);
				}
			}
			$tableList = array_unique($tableList);
		}
		$command =  self::getPrefixDumpCommand($cli);
		$command .= self::getMysqlCommandString('mysqldump', $mysqlDefaultFile, $host, $port, $user, $pass, $database, $tableList);
        $command .= ' --skip-dump-date'; // to be deterministic
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
		$command .= self::getDecryptionCommand($cli, $dumpFilePath);
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
		$command .= 'tempDir=`/bin/mktemp -d -t dumper-influx.XXXXXXXXXX` && /usr/bin/influxd backup'.$option.' $tempDir 2> /dev/null';
		$command .= ' && tar -C $tempDir -c .'; // we change to the given dir
		$command .= self::getCompressionCommand($cli);
		$command .= self::getEncryptionCommand($cli);
		$command .= self::getDumpFileForDumpCommand($cli);
		// mutig
		$command .= ' && /bin/rm -r "$tempDir"'; // hui jui jui

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

		$publicCert = $cli->getOption('publicCert');
		if( $publicCert!='' ){
			//  update 202311 - TODO change this to gpg - https://www.gnupg.org/gph/en/manual/x110.html

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
			$command = '| openssl smime -encrypt -aes256 -binary -outform D '.escapeshellarg((string) $publicCert);

			// old comment:
			// deterministic asyncronous ancryption
			// 1. get sha1 of sourcefile
			// 2. encrypt the sha1 with openssl async encryption
			// 3. write the encrypted pw to targetfile  - we need a fixed len here ?!
			// 4. use this sha1 as password for openssl enc -aes-256-cbc with fixed salt and append target file
			// decrypt:
			// 1. read the fixed len enxrypted key
			// 2. decrypt with private key
			// 3. use the result als password for openssl enc -d -aes-256-cbc for the rest of the file
		}

		// 2023-11 gpg is no solution for our symmetric deterministic encryption, because there is no way to provide a fixed salt

		$passFilePath = $cli->getOption('passFile');
		if( $passFilePath OR $cli->getOption('pass') ){

			if( !$passFilePath ){
				$passWordParameter = '-pass pass:'.escapeshellarg((string)$cli->getOption('pass'));
			}else{
				self::checkSecretFile($passFilePath);
				$passWordParameter = '--pass file:'.escapeshellarg((string)$passFilePath);
			}

			$command = '| openssl enc -aes-256-cbc -md sha256 -S '.bin2hex(self::SALT).' '.$passWordParameter;
		}

		return $command;
	}

	/**
	 * get decryption comand
	 * @param Ric_Client_Cli $cli
	 * @return string
	 */
	static protected function getDecryptionCommand($cli, $dumpFilePath){
		$command = '';

		$passFilePath = $cli->getOption('passFile');
		if( $passFilePath OR $cli->getOption('pass') ){

			// DETECT wich opensll version
			// because of this fukking opensll Schitt from https://www.openssl.org/docs/man3.1/man1/openssl-enc.html
			/*
			Please note that OpenSSL 3.0 changed the effect of the -S option.
			Any explicit salt value specified via this option is no longer prepended to the ciphertext when encrypting, and must again be explicitly provided when decrypting.
			Conversely, when the -S option is used during decryption, the ciphertext is expected to not have a prepended salt value.

			When using OpenSSL 3.0 or later to decrypt data that was encrypted with an explicit salt under OpenSSL 1.1.1
			do not use the -S option, the salt will then be read from the ciphertext.
			To generate ciphertext that can be decrypted with OpenSSL 1.1.1 do not use the -S option,
			the salt will be then be generated randomly and prepended to the output.


			detect openssl version >= 1.1.1 $isMinOpenssl111
			add parameter $dumpFilePath
			detect if dumpfile  $iSaltPrefixed
			use the right command for  slated
			$detect if gpg header


			*/



			if( !$passFilePath ){
				$passWordParameter = '-pass pass:'.escapeshellarg((string)$cli->getOption('pass'));
			}else{
				self::checkSecretFile($passFilePath);
				$passWordParameter = '--pass file:'.escapeshellarg((string)$passFilePath);
			}

			// check openssl version
			$command = 'openssl version';
			exec($command, $output, $status);
			if( $status!==0 ){
				throw new RuntimeException('openssl version check failed! :'.implode(';', $output));
			}
			$version = reset($output);  // OpenSSL 1.0.2g  1 Mar 2016    or    OpenSSL 3.0.2 15 Mar 2022 (Library: OpenSSL 3.0.2 15 Mar 2022)::
			if( preg_match('~^OpenSSL\s+(\d+\.\d+\.\d+).*~', $version, $matches) ){
				$version = $matches[1];
				$isMinOpenssl111 = version_compare($version, '1.1.1', '>='); // openssl 1.1.1 changed the default behaviour
			}else{
				// 'can not detect openssl version: '.$version.' assume its a newer version >(3.0.2) '.PHP_EOL;   but we can not uotput this info :-/
				$isMinOpenssl111 = true;
			}

			// we read the first 4 bytes of the file to determine the situation
			$handle = fopen($dumpFilePath, "rb");
			$firstBytes = fread($handle, 4);
			fclose($handle);
			$oldSaltedFile = substr($firstBytes, 0, 4) == 'Salt';

			if( $oldSaltedFile ){
				// "Salt"  means old file from prior v1.1.1 is prefix with sort and -md md5
				if( $isMinOpenssl111 ){
					// on version3 we have to omit the -S parameter and must set the -md md5
					$command = '| openssl enc -d -aes-256-cbc -md md5 '.$passWordParameter;
				}else{
					// i think we can omit the salt too because its in the file
					$command = '| openssl enc -d -aes-256-cbc '.$passWordParameter;
				}
			}else{
				// no "salt" means is not prefixed and -md sha256 .. we give the -dm sha256 this have to work before and after v1.1.1.
				$command = '| openssl enc -d -aes-256-cbc -md sha256 -S '.bin2hex(self::SALT).' '.$passWordParameter;
			}

			echo 'firstBytes: '.bin2hex($firstBytes).PHP_EOL;
			echo '$oldSaltedFile: '.$oldSaltedFile.PHP_EOL;
			echo '$isMinOpenssl111: '.$isMinOpenssl111.PHP_EOL;
			echo '$command: '.$command.PHP_EOL;

		}





		// password
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