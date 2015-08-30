<?php

class Ric_Server_File_Manager {
	protected $storageDir;

	/**
	 * Ric_Server_File_Manager constructor.
	 * @param $storageDir
	 * @throws RuntimeException
	 */
	public function __construct($storageDir){
		if( !is_dir($storageDir) ){
			throw new RuntimeException('document root ['.$storageDir.'] not found or not a dir!');
		}
		if( !is_writable($storageDir) ){
			throw new RuntimeException('document root ['.$storageDir.'] exists but is not a writable dir!');
		}
		$this->storageDir = $storageDir;
	}

	/**
	 * @param string $fileName
	 * @param string $version optional
	 * @throws RuntimeException
	 * @return string
	 */
	public function getFilePath($fileName, $version = ''){
		$filePath = '';
		if( $fileName!='' ){
			$fileDir = $this->storageDir.$this->getSplitDirectoryFilePath($fileName);

			if( !$version ){ // get the newest version
				$version = reset(array_keys($this->getAllVersions($fileName)));
				if( !$version ){
					throw new RuntimeException('no version of file not found', 404);
				}
			}
			$filePath = $fileDir.$fileName.'___'.$version;
		}
		return $filePath;
	}

	/**
	 * @param string $fileName
	 * @param string $tmpFilePath
	 * @throws RuntimeException
	 * @return string version
	 */
	public function storeFile($fileName, $tmpFilePath){

		// get correct filePath
		$version = sha1_file($tmpFilePath);
		$filePath = $this->getFilePath($fileName, $version);

		// init splitDirectory
		$fileDir = dirname($filePath);
		if( !is_dir($fileDir) ){
			if( !mkdir($fileDir, 0755, true) ){
				throw new RuntimeException('make splitDirectory failed: '.$fileDir);
			}
		}

		if( !rename($tmpFilePath, $filePath) ){
			$version = '';
		}
		return $version;
	}

	/**
	 * @param string $fileName
	 * @param string $version
	 * @param int $timestamp
	 */
	public function updateTimestamp($fileName, $version, $timestamp){
		$filePath = $this->getFilePath($fileName, $version);
		touch($filePath, $timestamp);
	}

	/**
	 * delete a specific or all versions of the File
	 * @param string $fileName
	 * @param string $version
	 * @return int
	 */
	public function deleteFile($fileName, $version){
		$filesDeleted = 0;
		if( $version==='' ){
			foreach( $this->getAllVersions($fileName) as $version => $timestamp ){
				$filesDeleted += $this->deleteFile($fileName, $version);
			}
		}else{
			$filePath = $this->getFilePath($fileName, $version);
			if( file_exists($filePath) ){
				unlink($filePath);
				$filesDeleted++;
			}
		}
		return $filesDeleted;
	}

	/**
	 * @param string $fileName
	 * @return string
	 */
	protected function getSplitDirectoryFilePath($fileName){
		$fileNameMd5 = md5($fileName);
		return substr($fileNameMd5, -1, 1).DIRECTORY_SEPARATOR.substr($fileNameMd5, -2, 1).DIRECTORY_SEPARATOR;
	}

	/**
	 * returns [version3=>ts3, version2=>ts2, version1... ]
	 * @param string $fileName
	 * @return array|int
	 */
	public function getAllVersions($fileName){
		$versions = [];
		$fileDir = $this->storageDir.$this->getSplitDirectoryFilePath($fileName);
		foreach( glob($fileDir.$fileName.'___*') as $entryFileName ){
			/** @noinspection PhpUnusedLocalVariableInspection */
			list($fileName, $version) = $this->extractVersionFromFullFileName($entryFileName);
			$fileTimestamp = filemtime($entryFileName);
			$versions[$version] = $fileTimestamp;
		}
		arsort($versions); // order by newest version
		return $versions;
	}

	/**
	 * @param string $pattern
	 * @param int $start
	 * @param int $limit
	 * @return Ric_Server_File_FileInfo[]
	 */
	public function getFileNamesForPattern($pattern = '', $start = 0, $limit = 100){
		$fileNames = [];
		$dirIterator = new RecursiveDirectoryIterator($this->storageDir);
		$iterator = new RecursiveIteratorIterator($dirIterator, RecursiveIteratorIterator::SELF_FIRST);
		$index = -1;
		foreach( $iterator as $splFileInfo ){
			/** @var SplFileInfo $splFileInfo */
			if( $splFileInfo->getPath()==$this->storageDir.'intern' ){
				continue; // skip our internal files
			}
			if( $splFileInfo->isFile() ){
				/** @noinspection PhpUnusedLocalVariableInspection */
				list($fileName, $version) = $this->extractVersionFromFullFileName($splFileInfo->getFilename());
				if( $pattern!=null AND !preg_match($pattern, $fileName) ){
					continue;
				}
				$index++;
				if( $index<$start ){
					continue;
				}
				$fileNames[$fileName] = $fileName;
				if( count($fileNames)>=$limit ){
					break;
				}
			}
		}
		$fileNames = array_values($fileNames);
		return $fileNames;
	}

	/**
	 * @param string $fileName
	 * @param string $version
	 * @return false|Ric_Server_File_FileInfo
	 */
	public function getFileInfo($fileName, $version){
		$info = false;
		$filePath = $this->getFilePath($fileName, $version);
		if( file_exists($filePath) ){
			list($fileName, $version) = $this->extractVersionFromFullFileName($filePath);
			$info = new Ric_Server_File_FileInfo(
					$fileName,
					$version,
					sha1_file($filePath),
					filesize($filePath),
					filemtime($filePath)
			);
		}
		return $info;
	}

	/**
	 * returns the result of "du storageDir" in bytes
	 * this is linux dependent, if want it more flexible, make it ;-)
	 * @throws RuntimeException
	 * @return int
	 */
	public function getDirectorySize(){
		$command = '/usr/bin/du -bs '.escapeshellarg($this->storageDir);
		exec($command, $output, $status);
		if( $status!==0 OR count($output)!=1 ){
			throw new RuntimeException('du failed with status: '.$status);
		}
		$size = intval(reset($output));
		return $size;
	}

	/**
	 * $fullFileName - fileName or filePath with Version error.log___234687683724...
	 * @param string $fullFileName
	 * @return string[]
	 * @throws RuntimeException
	 */
	protected function extractVersionFromFullFileName($fullFileName){
		if( preg_match('~^(.*)___(\w+)$~', basename($fullFileName), $matches) ){
			$fileName = $matches[1];
			$version = $matches[2];
		}else{
			throw new RuntimeException('unexpected fileName:'.$fullFileName);
		}
		return [$fileName, $version];
	}
}