<?php

class Ric_Server_File_FileInfo
{
    protected $name = '';
    protected $version = '';
    protected $sha1 = '';
    protected $size = 0;
    protected $timestamp = 0;

    /**
     * Ric_Server_File_FileInfo constructor.
     * @param string $name
     * @param string $sha1
     * @param int $size
     * @param int $timestamp
     */
    public function __construct($name, $version, $sha1, $size, $timestamp)
    {
        $this->name = $name;
        $this->version = $version;
        $this->sha1 = $sha1;
        $this->size = $size;
        $this->timestamp = $timestamp;
    }


    /**
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * @return string
     */
    public function getVersion()
    {
        return $this->getSha1();
    }

    /**
     * @return string
     */
    public function getSha1()
    {
        return $this->sha1;
    }

    /**
     * @return int
     */
    public function getSize()
    {
        return $this->size;
    }

    /**
     * @return int
     */
    public function getTimestamp()
    {
        return $this->timestamp;
    }

    /**
     * @return string
     */
    public function getDateTime()
    {
        return date('Y-m-d H:i:s', $this->timestamp);
    }


}