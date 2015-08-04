<?php

class Ric_Server_File_Manager{
    protected $storeDir;

    /**
     * Ric_Server_File_Manager constructor.
     * @param $storeDir
     */
    public function __construct($storeDir)
    {
        $this->storeDir = $storeDir;
    }


    /**
     * @param string $fileName
     * @param string $version optional
     * @return string
     */
    public function getFilePath($fileName, $version=''){
        $filePath = '';
        if( $fileName!='' ){
            $fileDir = $this->storeDir.$this->getSplitDirectoryFilePath($fileName);

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
    public function createVersion($fileName, $tmpFilePath){

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
        $fileDir = $this->storeDir.$this->getSplitDirectoryFilePath($fileName);
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
     * @param string $filePath
     * @return array
     */
    public function getFileInfo($filePath){
        list($fileName, $version) = $this->extractVersionFromFullFileName($filePath);
        $fileTimestamp = filemtime($filePath);
        $info = [
            'name' => $fileName,
            'version' => $version,
            'sha1' => sha1_file($filePath), // ja sollte das selbe wie version sein, das bestätigt aber, das das file noch physisch korrekt ist
            'size' => filesize($filePath),
            'timestamp' => $fileTimestamp,
            'dateTime' => date('Y-m-d H:i:s', $fileTimestamp),
        ];
        return $info;
    }

    /**
     * returns the result of "du storeDir" in bytes
     * this is linux dependent, if want it more flexible, make it ;-)
     * @throws RuntimeException
     * @return int
     */
    public function getDirectorySize(){
        $command = '/usr/bin/du -bs '.escapeshellarg($this->storeDir);
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