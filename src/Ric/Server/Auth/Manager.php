<?php

class Ric_Server_Auth_Manager {
	protected $readerToken = '';
	protected $writerToken = '';
	protected $adminToken = '';

	/**
	 * Ric_Server_Auth_Manager constructor.
	 * @param array $config [string => string]
	 */
	public function __construct($config){
		$this->readerToken = $config['readerToken'];
		$this->writerToken = $config['writerToken'];
		$this->adminToken = $config['adminToken'];
	}

	/**
	 * user admin>writer>reader
	 * @param string $user
	 * @param bool $isRequired
	 * @return bool
	 * @throws RuntimeException
	 */
	public function auth($user = 'reader', $isRequired = true){
		$isAuth = false;

		$userRole = 'guest';

		$token = H::getRP('token');

		if( $token==$this->readerToken OR ''==$this->readerToken ){
			$userRole = 'reader';
		}
		if( $token==$this->writerToken OR ''==$this->writerToken ){
			$userRole = 'writer';
		}
		if( $token==$this->adminToken OR ''==$this->adminToken ){
			$userRole = 'admin';
		}

		if( $user=='admin' AND $userRole=='admin' ){
			$isAuth = true;
		}elseif( $user=='writer' AND in_array($userRole, ['writer', 'admin']) ){
			$isAuth = true;
		}elseif( $user=='reader' AND in_array($userRole, ['reader', 'writer', 'admin']) ){
			$isAuth = true;
		}

		if( !$isAuth AND $isRequired ){
			throw new RuntimeException('valid login needed', 403);
		}

		return $isAuth;
	}
}