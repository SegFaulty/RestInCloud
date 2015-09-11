<?php

class Ric_Server_File_FileInfo {
	protected $name = '';
	protected $version = '';
	protected $size = 0;
	protected $timestamp = 0;

	/**
	 * Ric_Server_File_FileInfo constructor.
	 * @param string $name
	 * @param $version
	 * @param int $size
	 * @param int $timestamp
	 */
	public function __construct($name, $version, $size, $timestamp){
		$this->name = $name;
		$this->version = $version;
		$this->size = $size;
		$this->timestamp = $timestamp;
	}

	/**
	 * @return string
	 */
	public function getName(){
		return $this->name;
	}

	/**
	 * @return string
	 */
	public function getVersion(){
		return $this->version;
	}

	/**
	 * @return int
	 */
	public function getSize(){
		return $this->size;
	}

	/**
	 * @return int
	 */
	public function getTimestamp(){
		return $this->timestamp;
	}

	/**
	 * @return string
	 */
	public function getDateTime(){
		return date('Y-m-d H:i:s', $this->timestamp);
	}

}