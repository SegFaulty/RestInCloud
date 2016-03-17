<?php

require_once __DIR__ . '/../../../bootstrap.php';

class Test_Ric_Server_ServerTest extends \PHPUnit_Framework_TestCase{

	public function test_dumpFile(){
		$dumper = new Test_Ric_Dumper_Dumper();
		$tests = [
				['command' => '', 'status' => 1, 'contains' => 'needs to be help, dump or restore'],
				['command' => 'dump', 'status' => 1, 'contains' => 'needs to be a resource type'],
				['command' => 'dump file', 'status' => 1, 'contains' => 'min 3 arguments required'],
				['command' => 'dump file testfile', 'status' => 1, 'contains' => 'source file not found: '],
				['command' => 'dump file '.__DIR__.'/DumperTest.php --test', 'status' => 0, 'contains' => 'cat /home/www/RestInCloud/tests/Test/Ric/Dumper/DumperTest.php | bzip2 -9'],
		];
		foreach( $tests as $test ){
			$args = explode(' ', $test['command']);
			array_unshift($args, 'dumperScript');
			ob_start();
			self::assertSame($test['status'], $dumper->handleExecute($args, [], 'helpString'));
			$out = ob_get_contents();
			ob_end_clean();
			self::assertContains($test['contains'], $out);
		}
	}

	public function test_dumpDir(){
		$dumper = new Test_Ric_Dumper_Dumper();
		$tests = [
				['command' => 'dump dir', 'status' => 1, 'contains' => 'min 3 arguments required'],
				['command' => 'dump dir testfile', 'status' => 1, 'contains' => 'source dir not found'],
				['command' => 'dump dir '.__DIR__.'/DumperTest.php --test', 'status' => 1, 'contains' => 'source dir not found'],
				['command' => 'dump dir '.__DIR__.' --test', 'status' => 0, 'contains' => '"tar -cp /home/www/RestInCloud/tests/Test/Ric/Dumper | bzip2 -9"'],
				['command' => 'dump dir '.__DIR__.' --test', 'status' => 0, 'contains' => '"tar -cp /home/www/RestInCloud/tests/Test/Ric/Dumper | bzip2 -9"'],
		];
		foreach( $tests as $test ){
			$args = explode(' ', $test['command']);
			array_unshift($args, 'dumperScript');
			ob_start();
			self::assertSame($test['status'], $dumper->handleExecute($args, [], 'helpString'));
			$out = ob_get_contents();
			ob_end_clean();
			self::assertContains($test['contains'], $out);
		}
	}

	public function test_parseMysqlResourceString(){
		// [{user}:{pass}@][{server}[:{port}]]/{dataBase}[/{tableNamePattern}]
		$resourceString = 'user:pass@server:3307/database/table';
		$expected = ['user','pass','server','3307','database','table'];
		self::assertEquals($expected, Test_Ric_Dumper_Dumper::parseMysqlResourceString($resourceString));

		$resourceString = 'debian-main-tainer:pass@server:3307/database/table';
		$expected = ['debian-main-tainer','pass','server','3307','database','table'];
		self::assertEquals($expected, Test_Ric_Dumper_Dumper::parseMysqlResourceString($resourceString));
		// no user pw
		$resourceString = 'server:3307/database/table';
		$expected = ['','','server','3307','database','table'];
		self::assertEquals($expected, Test_Ric_Dumper_Dumper::parseMysqlResourceString($resourceString));
		// default server
		$resourceString = '/database/table';
		$expected = ['','','localhost','3306','database','table'];
		self::assertEquals($expected, Test_Ric_Dumper_Dumper::parseMysqlResourceString($resourceString));
		// default port
		$resourceString = 'server/database/table';
		$expected = ['','','server','3306','database','table'];
		self::assertEquals($expected, Test_Ric_Dumper_Dumper::parseMysqlResourceString($resourceString));
		// no table
		$resourceString = 'server/database';
		$expected = ['','','server','3306','database',''];
		self::assertEquals($expected, Test_Ric_Dumper_Dumper::parseMysqlResourceString($resourceString));
	}

}

class Test_Ric_Dumper_Dumper extends Ric_Dumper_Dumper {

	static public function parseMysqlResourceString($resourceString){
		return parent::parseMysqlResourceString($resourceString);
	}

	static protected function stdOut($msg){
		echo $msg;
	}

	/**
	 * @param string $msg
	 */
	static protected function stdErr($msg){
		echo $msg;
	}


}