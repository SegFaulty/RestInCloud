<?php

class Ric_Server_File_Manager{
    protected $storageDir;

    /**
     * Ric_Server_File_Manager constructor.
     * @param $storageDir
     */
    public function __construct($storageDir) {
        if( !is_dir($storageDir) OR !is_writable($storageDir) ){
            throw new RuntimeException('document root ['.$storageDir.'] is not a writable dir!');
        }
        $this->storageDir = $storageDir;
    }


    /**
     * @param string $fileName
     * @param string $version optional
     * @return string
     */
    public function getFilePath($fileName, $version=''){
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

        if(!rename($tmpFilePath, $filePath)){
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
     * @param string $fileName
     * @param string $version
     * @return bool
     */
    public function markFileAsDeleted($fileName, $version){
        $result = false;
        $filePath = $this->getFilePath($fileName, $version);
        if( file_exists($filePath) AND filemtime($filePath)!=Ric_Server_Definition::MAGIC_DELETION_TIMESTAMP ){
            $result = touch($filePath, Ric_Server_Definition::MAGIC_DELETION_TIMESTAMP) ;
        }
        return $result;
    }

    /**
     * mark one or all versions of the File as deleted
     * @param string $fileName
     * @param string $version
     * @return int
     */
    public function deleteFile($fileName, $version){
        $filesDeleted = 0;
        if( $version ){
            $filesDeleted+= $this->markFileAsDeleted($fileName, $version);
        }else{
            foreach( $this->getAllVersions($fileName) as $version=>$timestamp){
                $filesDeleted+= $this->markFileAsDeleted($fileName, $version);
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
        return substr($fileNameMd5,-1,1).DIRECTORY_SEPARATOR.substr($fileNameMd5,-2,1).DIRECTORY_SEPARATOR;
    }

    /**
     * @param string $fileName
     * @param bool $includeDeleted
     * @return array|int
     */
    public function getAllVersions($fileName, $includeDeleted=false){
        $versions = [];
        $fileDir = $this->storageDir.$this->getSplitDirectoryFilePath($fileName);
        foreach(glob($fileDir.$fileName.'___*') as $entryFileName) {
            /** @noinspection PhpUnusedLocalVariableInspection */
            list($fileName, $version) = $this->extractVersionFromFullFileName($entryFileName);
            $fileTimestamp = filemtime($entryFileName);
            if( $includeDeleted OR $fileTimestamp!=Ric_Server_Definition::MAGIC_DELETION_TIMESTAMP ){
                $versions[$version] = $fileTimestamp;
            }
        }
        arsort($versions); // order by newest version
        return $versions;
    }

    /**
     * @param string $pattern
     * @param bool|false $showDeleted
     * @param int $start
     * @param int $limit
     * @return Ric_Server_File_FileInfo[]
     */
    public function getFileInfosForPattern($pattern='', $showDeleted=false, $start=0, $limit=100){
        $result = [];
        $dirIterator = new RecursiveDirectoryIterator($this->storageDir);
        $iterator = new RecursiveIteratorIterator($dirIterator, RecursiveIteratorIterator::SELF_FIRST);
        $index = -1;
        foreach( $iterator as $splFileInfo ){ /** @var SplFileInfo $splFileInfo */
            if( $splFileInfo->getPath()==$this->storageDir.'intern' ){
                continue; // skip our internal files
            }
            if( $splFileInfo->isFile() ){
                /** @noinspection PhpUnusedLocalVariableInspection */
                list($fileName, $version) = $this->extractVersionFromFullFileName($splFileInfo->getFilename());
                if( $pattern!=null AND !preg_match($pattern, $fileName) ){
                    continue;
                }
                if( $splFileInfo->getMTime()==Ric_Server_Definition::MAGIC_DELETION_TIMESTAMP AND !$showDeleted ){
                    continue;
                }
                $index++;
                if( $index<$start ){
                    continue;
                }
                $result[] = $this->getFileInfo($fileName, $version);
                if( count($result)>=$limit ){
                    break;
                }
            }
        }
        return $result;
    }

    /**
     * @param string $fileName
     * @param string $version
     * @return Ric_Server_File_FileInfo
     */
    public function getFileInfo($fileName, $version){
        $filePath = $this->getFilePath($fileName, $version);
        list($fileName, $version) = $this->extractVersionFromFullFileName($filePath);
        $fileTimestamp = filemtime($filePath);
        $info = new Ric_Server_File_FileInfo($fileName, $version, sha1_file($filePath), filesize($filePath), $fileTimestamp);
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