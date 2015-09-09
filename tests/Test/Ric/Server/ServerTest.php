<?php

require_once __DIR__ . '/../../../bootstrap.php';

class Test_Ric_Server_ServerTest extends \PHPUnit_Framework_TestCase {
	protected $storageDir;
	protected $configManager;

	/**
	 * Test_Ric_Server_ServerTest constructor.
	 */
	public function __construct($name = null, array $data = array(), $dataName = ''){
		parent::__construct($name, $data, $dataName);
		$testsDir = PROJECT_ROOT.'var/tests/';
		if( !is_writeable($testsDir) ){
			throw new Exception('writable test storage directory is needed at '.$testsDir);
		}
		$this->storageDir = $testsDir.uniqid(__CLASS__.'_').'/';
		if( !mkdir($this->storageDir) ){
			throw new Exception('failed to create tmp test dir '.$this->storageDir);
		}
		$this->configManager = new Test_Ric_Server_TestConfigManager(__DIR__ . '/config.json');
		$this->configManager->set('storeDir', $this->storageDir);
	}


	/**
	 * @return Ric_Server_Server
	 */
	protected function getServer(){
		return new Ric_Server_Server($this->configManager);
	}

	/**
	 * @return Test_Ric_Server_TestConfigManager
	 */
	public function getConfigManager(){
		return $this->configManager;
	}

	public function testSaveInCloud(){
		$testFile = $this->storageDir . 'test.txt';
		$fileName = str_replace(':', '_', __METHOD__);
		$data = 'data' . rand(1000, 9999);
		$timestamp = time()-rand(1000, 9999);
		// create test file
		file_put_contents($testFile, $data);
		// save file in cloud
		$server = $this->getServer();
		$response = $server->saveFileInCloud($testFile, $fileName, Ric_Server_Definition::RETENTION__LAST7, $timestamp, false);

		// check response
		self::assertEquals('OK', $response->getResult()['status']);
		$headers = $response->getHeaders();
		self::assertEquals(1, count($headers));
		$header = reset($headers);
		self::assertEquals('HTTP/1.1 201 Created', $header['text']);
		self::assertEquals(201, $header['code']);

		// check saved file
		$filePath = $this->findSingleFile($this->storageDir);
		self::assertNotEmpty($filePath);
		self::assertEquals($timestamp, filemtime($filePath));
		self::assertEquals($data, file_get_contents($filePath));
	}

	public function testCheckFile(){
		$testFile = $this->storageDir . 'test.txt';
		$fileName = str_replace(':', '_', __METHOD__);
		$data = 'data' . rand(1000, 9999);
		$timestamp = time()-rand(1000, 9999);

		// create test file
		file_put_contents($testFile, $data);
		$sha1 = sha1_file($testFile);

		// create file
		$server = $this->getServer();
		$server->saveFileInCloud($testFile, $fileName, Ric_Server_Definition::RETENTION__LAST7, $timestamp, true);
		// check file
		$response = $server->checkFile($fileName, $sha1, $sha1, strlen($data), $timestamp, 0);
		$result = $response->getResult();
		self::assertTrue(is_array($result));
		self::assertNotEmpty($result);
		self::assertEquals('CRITICAL', $result['status']);
		self::assertContains('not enough replicas', $result['msg']);
		self::assertNotEmpty($result['fileInfo']);
	}

	public function testRefreshFile(){
		$fileName = str_replace(':', '_', __METHOD__);
		$sha1 = $this->createTestFile($fileName);
		$server = $this->getServer();
		$timestamp = time()-rand(1000, 9999);
		$response = $server->refreshFile($fileName, $sha1, $timestamp, true);
		self::assertEquals('OK', $response->getResult()['status']);
	}

	public function testDeleteFile(){
		$filePath = $this->findSingleFile($this->storageDir);
		self::assertEmpty($filePath);

		$fileName = str_replace(':', '_', __METHOD__);
		$sha1 = $this->createTestFile($fileName);

		$filePath = $this->findSingleFile($this->storageDir);
		self::assertNotEmpty($filePath);

		$server = $this->getServer();
		$response = $server->deleteFile($fileName, $sha1);
		self::assertEquals(['status'=>'OK','filesDeleted' => 1], $response->getResult());
		$filePath = $this->findSingleFile($this->storageDir);
		self::assertEmpty($filePath);

	}

	public function testListAllVersions(){
		$fileName = str_replace(':', '_', __METHOD__);
		$version1 = $this->createTestFile($fileName);
		$version2 = $this->createTestFile($fileName);
		self::assertNotEquals($version1, $version2);

		$server = $this->getServer();
		$response = $server->listVersions($fileName, 10);
		$result = $response->getResult();
		self::assertEquals(2, count($result));
		$versions = [];
		foreach( $result as $fileArray ){
			self::assertTrue(isset($fileArray['version']));
			$versions[] = $fileArray['version'];
		}
		self::assertContains($version1, $versions);
		self::assertContains($version2, $versions);
	}

	public function testListFileNames(){
		$fileName = str_replace(':', '_', __METHOD__);
		$version1 = $this->createTestFile($fileName);
		$version2 = $this->createTestFile($fileName);
		self::assertNotEquals($version1, $version2);

		$server = $this->getServer();
		$response = $server->listFileNames('%testListFileNames%', 0, 10);
		$result = $response->getResult();
		self::assertEquals([$fileName], $result);


		$response = $server->listFileNames('%testListFileNamesBROKEN%', 0, 10);
		$result = $response->getResult();
		self::assertEquals([], $result);
	}

	public function testAddServer(){
		$serverHostPort = 'localhost:6757';

		self::assertEquals(null, $this->configManager->getFromRuntimeConfig('servers'));

		// server will not respond correctly
		self::setExpectedException('RuntimeException', 'curl request failed');
		$server = $this->getServer();
		$server->addServer($serverHostPort);
	}

	public function testRemoveServer(){
		$serverHostPort = 'localhost:5463';

		$this->configManager->setRuntimeValue('servers', [$serverHostPort]);

		$server = $this->getServer();
		$response = $server->removeServer($serverHostPort);
		self::assertEquals(['status' => 'OK'], $response->getResult());
		self::assertEquals([], $this->configManager->getFromRuntimeConfig('servers'));
	}

	protected function createTestFile($fileName){
		$testFile = $this->storageDir . 'test.txt';
		$data = 'data' . rand(1000, 9999);
		$timestamp = time()-rand(1000, 9999);

		// create test file
		file_put_contents($testFile, $data);
		$sha1 = sha1_file($testFile);

		// create file
		$server = $this->getServer();
		$server->saveFileInCloud($testFile, $fileName, Ric_Server_Definition::RETENTION__LAST7, $timestamp, true);
		return $sha1;
	}

	/**
	 * @param string $dirOrFile
	 * @return string
	 * @throws Exception
	 */
	protected function findSingleFile($dirOrFile){
		foreach( glob($dirOrFile . '*') as $filePath ){
			if( substr($filePath, 0, 1)=='.' ){
				continue;
			}
			if( is_file($filePath) ){
				return $filePath;
			} elseif( is_dir($filePath) ) {
				if( substr($filePath, -1)!='/' ){
					$filePath .= '/';
				}
				return $this->findSingleFile($filePath);
			}
		}
		return null;
	}

	/**
	 * @param string $dir
	 * @param bool $deleteParent
	 * @throws RuntimeException
	 * @return bool
	 */
	public static function delTree($dir, $deleteParent=true) {
		if( realpath($dir)<realpath(PROJECT_ROOT) ){
			throw new RuntimeException('unexpected path: '.$dir.' expected under: '.PROJECT_ROOT);
		}
		$files = array_diff(scandir($dir), array('.','..'));
		foreach( $files as $file ){
			$path = $dir.'/'.$file;
			if( is_dir($path) ){
				self::delTree($path);
			}else{
				unlink($path);
			}
		}
		if( $deleteParent ){
			rmdir($dir);
		}
	}

	public function tearDown(){
		self::delTree($this->storageDir);
		parent::tearDown();
	}
}