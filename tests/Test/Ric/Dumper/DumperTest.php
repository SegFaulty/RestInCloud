<?php

require_once __DIR__ . '/../../../bootstrap.php';

class Test_Ric_Server_ServerTest extends \PHPUnit_Framework_TestCase{

	public function test_parseMysqlResourceString(){
		// [{user}:{pass}@][{server}[:{port}]]/{dataBase}[/{tableNamePattern}]
		$resourceString = 'user:pass@server:3307/database/table';
		$expected = ['user','pass','server','3307','database','table'];
		self::assertEquals($expected, Test_Ric_Dumper_Dumper::parseMysqlResourceString($resourceString));
	}

}

class Test_Ric_Dumper_Dumper extends Ric_Dumper_Dumper {

	static public function parseMysqlResourceString($resourceString){
		return parent::parseMysqlResourceString($resourceString);
	}
}