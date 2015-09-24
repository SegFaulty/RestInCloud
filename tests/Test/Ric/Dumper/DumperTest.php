<?php

require_once __DIR__ . '/../../../bootstrap.php';

class Test_Ric_Server_ServerTest extends \PHPUnit_Framework_TestCase{

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
}