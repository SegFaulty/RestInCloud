<?php

class Ric_Server_Response {
	/**
	 * @var array
	 */
	protected $headers = [];
	/**
	 * @var string[]
	 */
	protected $output = [];

	protected $result = null;
	protected $outputFilePath = '';

	/**
	 * @return mixed
	 */
	public function getResult(){
		return $this->result;
	}

	/**
	 * @param mixed $result
	 */
	public function setResult($result){
		$this->result = $result;
	}

	/**
	 * @param string $text
	 * @param int $code
	 */
	public function addHeader($text, $code = 0){
		$this->headers[] = [
				'text' => $text,
				'code' => $code,
		];
	}

	/**
	 * @return array
	 */
	public function getHeaders(){
		return $this->headers;
	}

	/**
	 * @return string[]
	 */
	public function getOutput(){
		return $this->output;
	}

	/**
	 * @param string $text
	 */
	public function addOutput($text){
		$this->output[] = $text;
	}

	/**
	 * @param string $output
	 */
	public function setOutput($output){
		$this->output = [$output];

	}

	/**
	 * @param $filePath
	 */
	public function setOutputFilePath($filePath){
		$this->outputFilePath = $filePath;
	}

	/**
	 * @return string
	 */
	public function getOutputFilePath(){
		return $this->outputFilePath;
	}
}