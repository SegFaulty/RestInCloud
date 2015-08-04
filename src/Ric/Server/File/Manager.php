<?php

class Ric_Server_File_Manager{
    /**
     * @param string $filePathWithoutVersion
     * @param bool $includeDeleted
     * @return array|int
     */
    public function getAllVersions($filePathWithoutVersion, $includeDeleted=false){
        $versions = [];
        foreach(glob($filePathWithoutVersion.'___*') as $entryFileName) {
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
        $command = '/usr/bin/du -bs '.escapeshellarg($this->config['storeDir']);
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